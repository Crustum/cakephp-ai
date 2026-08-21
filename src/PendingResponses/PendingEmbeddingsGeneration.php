<?php
declare(strict_types=1);

namespace Crustum\Ai\PendingResponses;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Files\HasProviderId;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\ProviderFailedOverEvent;
use Crustum\Ai\Exception\EmbeddingsCountMismatchException;
use Crustum\Ai\Exception\FailoverableException;
use Crustum\Ai\Files\Audio;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Files\Image;
use Crustum\Ai\Files\RemoteAudio;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\RemoteVideo;
use Crustum\Ai\Files\Video;
use Crustum\Ai\Job\GenerateEmbeddingsJob;
use Crustum\Ai\Job\PendingDispatch;
use Crustum\Ai\PendingResponses\Trait\ResolvesProviderOptionsTrait;
use Crustum\Ai\Prompts\QueuedEmbeddingsPrompt;
use Crustum\Ai\Providers\Provider as AbstractProvider;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\QueuedEmbeddingsResponse;
use Crustum\Ai\Trait\ConditionableTrait;
use InvalidArgumentException;

/**
 * Pending embeddings generation request.
 *
 * Builder for configuring and executing embeddings generation requests.
 * Supports dimensions configuration, caching, timeout configuration,
 * and both synchronous and queued execution.
 */
class PendingEmbeddingsGeneration
{
    use ConditionableTrait;
    use ResolvesProviderOptionsTrait;

    /**
     * The dimensions for the embeddings.
     */
    protected ?int $dimensions = null;

    /**
     * The cache duration in seconds.
     */
    protected ?int $cacheSeconds = null;

    /**
     * Whether embeddings should be cached.
     */
    protected ?bool $shouldCache = null;

    /**
     * Whether embeddings should be cached individually per input.
     */
    protected ?bool $cacheIndividually = null;

    /**
     * The timeout (in seconds) for the embeddings generation.
     */
    protected int $timeout = 30;

    /**
     * Create a new pending embeddings generation instance.
     *
     * @param array<int, mixed> $inputs The inputs to embed (validated to string|Audio|Document|Image|Video)
     * @throws \InvalidArgumentException
     */
    public function __construct(protected array $inputs)
    {
        if (!array_is_list($inputs)) {
            throw new InvalidArgumentException('Inputs to embed must be a list, not an associative array.');
        }

        if ($inputs === []) {
            throw new InvalidArgumentException('At least one input is required to generate embeddings.');
        }

        foreach ($inputs as $index => $input) {
            if (is_string($input)) {
                if (empty(trim($input))) {
                    throw new InvalidArgumentException(sprintf('The input at index %d must be a non-blank string.', $index));
                }

                continue;
            }

            if (
                !$input instanceof Image
                && !$input instanceof Audio
                && !$input instanceof Document
                && !$input instanceof Video
            ) {
                throw new InvalidArgumentException(sprintf('The input at index %d must be a string or an image, audio, document, or video file.', $index));
            }
        }
    }

    /**
     * Specify the dimensions for the embeddings.
     *
     * @param int $dimensions Number of dimensions
     */
    public function dimensions(int $dimensions): static
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    /**
     * Enable or disable caching for this embedding request.
     *
     * @param int|null $seconds Cache duration in seconds, or 0/negative to disable
     * @param bool|null $individually Whether to cache each input's embedding separately
     */
    public function cache(?int $seconds = null, ?bool $individually = null): static
    {
        if (!is_null($seconds) && $seconds <= 0) {
            $this->shouldCache = false;
            $this->cacheSeconds = null;
            $this->cacheIndividually = null;

            return $this;
        }

        $this->shouldCache = true;
        $this->cacheSeconds = $seconds ?? Configure::read('Ai.caching.embeddings.seconds', 60 * 60 * 24 * 30);
        $this->cacheIndividually = $individually ?? $this->cacheIndividually;

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the embeddings generation.
     *
     * @param int $seconds Timeout in seconds
     */
    public function timeout(int $seconds = 30): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Generate the embeddings.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     * @throws \Crustum\Ai\Exception\FailoverableException if every configured provider fails to generate the embeddings.
     */
    public function generate(Lab|array|string|null $provider = null, ?string $model = null): EmbeddingsResponse
    {
        $providers = AbstractProvider::formatProviderAndModelList(
            $provider ?? Configure::read('Ai.default_for_embeddings'),
            $model,
        );

        $lastException = null;

        foreach ($providers as $provider => $model) {
            $provider = Ai::manager()->fakeableEmbeddingProvider($provider);

            $model ??= $provider->defaultEmbeddingsModel();

            $dimensions = $this->dimensions ?: $provider->defaultEmbeddingsDimensions();

            $providerOptions = $this->resolveProviderOptions($provider);

            try {
                return $this->shouldCacheIndividually()
                    ? $this->generateWithIndividualCaching($provider, $model, $dimensions, $providerOptions)
                    : $this->generateWithSharedCaching($provider, $model, $dimensions, $providerOptions);
            } catch (FailoverableException $e) {
                $lastException = $e;

                EventManager::instance()->dispatch(new ProviderFailedOverEvent($provider->name(), $model, $e));

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Generate the embeddings, caching the entire response under a single shared key.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider The provider instance
     * @param string $model The model name
     * @param int $dimensions The embedding dimensions
     * @param array<string, mixed> $providerOptions Provider options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    protected function generateWithSharedCaching(EmbeddingProvider $provider, string $model, int $dimensions, array $providerOptions): EmbeddingsResponse
    {
        $cached = $this->generateFromCache($provider, $model, $dimensions, $providerOptions);
        if ($cached instanceof EmbeddingsResponse) {
            return $cached;
        }

        $response = $provider->embeddings($this->inputs, $dimensions, $model, $this->timeout, $providerOptions);
        $this->cacheEmbeddings($provider, $model, $dimensions, $providerOptions, $response);

        return $response;
    }

    /**
     * Generate the embeddings, caching each input's embedding individually.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider The provider instance
     * @param string $model The model name
     * @param int $dimensions The embedding dimensions
     * @param array<string, mixed> $providerOptions Provider options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     * @throws \Crustum\Ai\Exception\EmbeddingsCountMismatchException if the provider returns an embedding count that does not match the input count.
     */
    protected function generateWithIndividualCaching(EmbeddingProvider $provider, string $model, int $dimensions, array $providerOptions): EmbeddingsResponse
    {
        $cached = $this->cachedIndividualEmbeddings($provider, $model, $dimensions, $providerOptions);

        if (count($this->inputs) === count($cached)) {
            return new EmbeddingsResponse(array_values($cached), 0, new Meta($provider->name(), $model));
        }

        $uncachedInputs = array_diff_key($this->inputs, $cached);

        $response = $provider->embeddings(array_values($uncachedInputs), $dimensions, $model, $this->timeout, $providerOptions);

        if (count($response->embeddings) !== count($uncachedInputs)) {
            throw new EmbeddingsCountMismatchException(count($uncachedInputs), count($response->embeddings));
        }

        $generated = array_combine(array_keys($uncachedInputs), $response->embeddings);

        $this->cacheIndividualEmbeddings($provider, $model, $dimensions, $providerOptions, $generated);

        $embeddings = $cached + $generated;

        ksort($embeddings);

        return new EmbeddingsResponse(array_values($embeddings), $response->tokens, $response->meta);
    }

    /**
     * Generate the embeddings from a cached response if possible.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider instance
     * @param string $model The model name
     * @param int $dimensions The embedding dimensions
     * @param array<string, mixed> $providerOptions Provider options
     */
    protected function generateFromCache(Provider $provider, string $model, int $dimensions, array $providerOptions): ?EmbeddingsResponse
    {
        if (!$this->shouldCache()) {
            return null;
        }

        $response = Cache::read($this->cacheKey($provider, $model, $dimensions, $providerOptions), $this->cacheConfig());

        if (!is_null($response)) {
            $response = json_decode((string)$response, true);

            return new EmbeddingsResponse($response['embeddings'], 0, new Meta(
                provider: $response['meta']['provider'],
                model: $response['meta']['model'],
            ));
        }

        return null;
    }

    /**
     * Cache the given embeddings response.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider instance
     * @param string $model The model name
     * @param int $dimensions The embedding dimensions
     * @param array<string, mixed> $providerOptions Provider options
     * @param \Crustum\Ai\Responses\EmbeddingsResponse $response The response to cache
     * @return void
     */
    protected function cacheEmbeddings(Provider $provider, string $model, int $dimensions, array $providerOptions, EmbeddingsResponse $response): void
    {
        if (!$this->shouldCache()) {
            return;
        }

        $this->cacheSeconds ?? Configure::read('Ai.caching.embeddings.seconds', 60 * 60 * 24 * 30);

        Cache::write(
            $this->cacheKey($provider, $model, $dimensions, $providerOptions),
            json_encode($response),
            $this->cacheConfig(),
        );
    }

    /**
     * Get the individually cached embeddings for the inputs, keyed by input index.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider instance
     * @param string $model The model name
     * @param int $dimensions The embedding dimensions
     * @param array<string, mixed> $providerOptions Provider options
     * @return array<int, array<float>>
     */
    protected function cachedIndividualEmbeddings(Provider $provider, string $model, int $dimensions, array $providerOptions): array
    {
        $keys = array_map(
            fn(mixed $input): string => $this->individualCacheKey($provider, $model, $dimensions, $providerOptions, $input),
            $this->inputs,
        );

        $values = Cache::readMany($keys, $this->cacheConfig());

        $embeddings = [];

        foreach ($keys as $index => $key) {
            $value = $values[$key] ?? null;

            if (!is_null($value)) {
                $embeddings[$index] = json_decode((string)$value, true);
            }
        }

        return $embeddings;
    }

    /**
     * Cache the given embeddings individually, keyed by input index.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider instance
     * @param string $model The model name
     * @param int $dimensions The embedding dimensions
     * @param array<string, mixed> $providerOptions Provider options
     * @param array<int, array<float>> $embeddings The embeddings keyed by input index
     * @return void
     */
    protected function cacheIndividualEmbeddings(Provider $provider, string $model, int $dimensions, array $providerOptions, array $embeddings): void
    {
        $values = [];

        foreach ($embeddings as $index => $embedding) {
            $values[$this->individualCacheKey($provider, $model, $dimensions, $providerOptions, $this->inputs[$index])] = json_encode($embedding);
        }

        Cache::writeMany($values, $this->cacheConfig());
    }

    /**
     * Get the cache key for the given embeddings request.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider instance
     * @param string $model The model name
     * @param int $dimensions The embedding dimensions
     * @param array<string, mixed> $providerOptions Provider options
     * @return string
     */
    protected function cacheKey(Provider $provider, string $model, int $dimensions, array $providerOptions): string
    {
        return 'cakephp-embeddings:' . hash('sha256', json_encode([
            'driver' => $provider->driver(),
            'model' => $model,
            'dimensions' => $dimensions,
            'options' => $this->fingerprintProviderOptions($providerOptions),
            'inputs' => array_map($this->normalizeInputForCache(...), $this->inputs),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Get the cache key for an individual embeddings input.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider instance
     * @param string $model The model name
     * @param int $dimensions The embedding dimensions
     * @param array<string, mixed> $providerOptions Provider options
     * @param mixed $input The individual input
     * @return string
     */
    protected function individualCacheKey(Provider $provider, string $model, int $dimensions, array $providerOptions, mixed $input): string
    {
        return 'cakephp-embeddings:' . hash('sha256', json_encode([
            'driver' => $provider->driver(),
            'model' => $model,
            'dimensions' => $dimensions,
            'options' => $this->fingerprintProviderOptions($providerOptions),
            'input' => $this->normalizeInputForCache($input),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Generate fingerprint for provider options.
     *
     * @param array<string, mixed> $providerOptions Provider options
     * @return string
     */
    protected function fingerprintProviderOptions(array $providerOptions): string
    {
        if ($providerOptions === []) {
            return '';
        }

        $normalized = $this->normalizeForFingerprint($providerOptions);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Recursively sort associative keys so the fingerprint is insensitive to key order.
     *
     * @param mixed $value The value to normalize
     */
    protected function normalizeForFingerprint(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeForFingerprint(...), $value);
        }

        ksort($value);

        return array_map($this->normalizeForFingerprint(...), $value);
    }

    /**
     * Normalize an embeddings input into a deterministic cache representation.
     *
     * @param mixed $input Embeddings input
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    protected function normalizeInputForCache(mixed $input): array
    {
        if (is_string($input)) {
            return [
                'type' => 'text',
                'value' => $input,
            ];
        }

        $type = match (true) {
            $input instanceof Image => 'image',
            $input instanceof Audio => 'audio',
            $input instanceof Document => 'document',
            $input instanceof Video => 'video',
            default => throw new InvalidArgumentException('Unsupported embeddings input type [' . get_debug_type($input) . ']'),
        };

        return match (true) {
            $input instanceof HasProviderId => [
                'type' => $type,
                'source' => 'provider',
                'id' => $input->id(),
                'name' => $input->name(),
            ],
            $input instanceof RemoteImage,
            $input instanceof RemoteAudio,
            $input instanceof RemoteDocument,
            $input instanceof RemoteVideo => [
                'type' => $type,
                'source' => 'remote',
                'url' => $input->url,
                'mime' => $input->declaredMimeType(),
                'name' => $input->name(),
            ],
            $input instanceof StorableFile => [
                'type' => $type,
                'source' => 'content',
                'hash' => hash('sha256', $input->content()),
                'mime' => $input->mimeType(),
                'name' => $input->name(),
            ],
            default => throw new InvalidArgumentException('Unsupported embeddings input type [' . get_debug_type($input) . ']'),
        };
    }

    /**
     * Queue the generation of the embeddings.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\QueuedEmbeddingsResponse
     */
    public function queue(Lab|array|string|null $provider = null, ?string $model = null): QueuedEmbeddingsResponse
    {
        if (Ai::manager()->embeddingsAreFaked()) {
            Ai::manager()->recordEmbeddingsGeneration(
                new QueuedEmbeddingsPrompt(
                    $this->inputs,
                    $this->dimensions,
                    $provider,
                    $model,
                    $this->timeout,
                    is_array($this->providerOptions) ? $this->providerOptions : [],
                ),
            );
        }

        return new QueuedEmbeddingsResponse(
            new PendingDispatch(
                GenerateEmbeddingsJob::class,
                GenerateEmbeddingsJob::payload($this, $provider, $model),
            ),
        );
    }

    /**
     * Get the cache configuration name for embeddings.
     *
     * @return string
     */
    protected function cacheConfig(): string
    {
        return Configure::read('Ai.caching.embeddings.store', 'default');
    }

    /**
     * Determine if embeddings should be cached.
     *
     * @return bool
     */
    protected function shouldCache(): bool
    {
        if (!is_null($this->shouldCache)) {
            return $this->shouldCache;
        }

        return (bool)Configure::read('Ai.caching.embeddings.cache', false);
    }

    /**
     * Determine if embeddings should be cached individually per input.
     *
     * @return bool
     */
    protected function shouldCacheIndividually(): bool
    {
        if (!$this->shouldCache()) {
            return false;
        }

        return $this->cacheIndividually
            ?? (bool)Configure::read('Ai.caching.embeddings.individually', false);
    }
}
