<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Gateway\ClassificationGateway;
use Crustum\Ai\Contracts\Providers\ClassificationProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files\File;
use Crustum\Ai\Gateway\Trait\AnswersQuestionsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Gateway\Trait\ResolvesInlineImagesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Responses\ClassificationResponse;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;
use RuntimeException;

/**
 * TypeSafe classification gateway.
 */
class TypeSafeGateway implements ClassificationGateway
{
    use AnswersQuestionsTrait {
        classify as answerQuestions;
    }
    use HandlesFailoverErrorsTrait;
    use MergesHeadersTrait;
    use ResolvesInlineImagesTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $typeSafeHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $typeSafeHttpTimeout = 30;

    /**
     * Get the path of the endpoint that answers questions.
     *
     * @return string
     */
    protected function classificationEndpoint(): string
    {
        return '/systemone';
    }

    /**
     * Answer the given questions about the state, sending image attachments as referenced assets.
     *
     * @param \Crustum\Ai\Contracts\Providers\ClassificationProvider $provider Classification provider
     * @param string $model Model name
     * @param array<string, mixed>|string $state State to classify
     * @param array<string, \Crustum\Ai\Contracts\Question> $questions Questions to answer
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @param array<int|string, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile> $attachments Attachments to classify alongside the state
     * @return \Crustum\Ai\Responses\ClassificationResponse
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): ClassificationResponse {
        if ($attachments === []) {
            return $this->answerQuestions($provider, $model, $state, $questions, $timeout, $providerOptions);
        }

        $references = array_map(Text::uuid(...), $attachments);

        return $this->answerQuestions(
            $provider->withHeaders(['TypeSafe-Danger-Zone-Images' => 'true']),
            $model,
            $this->stateWithImages($state, $references),
            $questions,
            $timeout,
            [...$providerOptions, 'assets' => array_combine($references, array_map($this->mapImage(...), $attachments))],
        );
    }

    /**
     * Place the image references in the state: keyed by name into a structured state, or labelled by name in a list.
     *
     * @param array<array-key, mixed>|string $state State to classify
     * @param array<int|string, string> $references Image references keyed by attachment name
     * @return array<int|string, mixed>
     * @throws \InvalidArgumentException if a structured state already uses a key the images need.
     */
    protected function stateWithImages(string|array $state, array $references): array
    {
        if (is_string($state) || array_is_list($state)) {
            $labeled = array_map(
                fn(int|string $name, string $reference): string => is_string($name) ? "{$name}: {$reference}" : $reference,
                array_keys($references),
                $references,
            );

            return [...(is_string($state) ? [$state] : $state), ...$labeled];
        }

        $images = array_filter($references, is_string(...), ARRAY_FILTER_USE_KEY);
        $unnamed = array_values(array_filter($references, is_int(...), ARRAY_FILTER_USE_KEY));

        if ($unnamed !== []) {
            $images['images'] = $unnamed;
        }

        $duplicates = array_intersect_key($state, $images);

        if ($duplicates !== []) {
            throw new InvalidArgumentException('TypeSafe adds images to a structured state under their attachment names, or [images] when unnamed; [' . implode(', ', array_keys($duplicates)) . '] already in the state.');
        }

        return [...$state, ...$images];
    }

    /**
     * Map an image to a base64 System One asset.
     *
     * @param \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile $attachment Image attachment
     * @return array<string, mixed>
     * @throws \InvalidArgumentException if the attachment is not a JPEG or PNG image with inline content.
     */
    protected function mapImage(File|UploadedFile $attachment): array
    {
        [$image, $content, $mime] = $this->resolveInlineImage($attachment, 'TypeSafe decisions', [
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
        ]);

        return array_merge($image->providerOptions(Lab::TypeSafe), [
            'type' => 'image',
            'format' => $mime === 'image/png' ? 'png' : 'jpg',
            'data_base64' => base64_encode($content),
        ]);
    }

    /**
     * Get the answering model, preferring the checkpoint a routing server such as laya-serve chose.
     *
     * @param array<string, mixed> $data Response data
     * @param string $model Model name
     * @return string
     */
    protected function answeringModel(array $data, string $model): string
    {
        return $data['routing']['model'] ?? $data['model'] ?? $model;
    }

    /**
     * Get an HTTP client for the TypeSafe API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, int $timeout = 30): static
    {
        $this->typeSafeHttpProvider = $provider;
        $this->typeSafeHttpTimeout = $timeout;

        return $this;
    }

    /**
     * Send a POST request to the TypeSafe API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body = []): HttpResponseInterface
    {
        $provider = $this->typeSafeHttpProvider;

        if (!$provider instanceof Provider) {
            throw new RuntimeException('TypeSafe HTTP provider is not configured.');
        }

        $config = $provider->additionalConfiguration();

        $url = rtrim((string)($config['url'] ?? 'https://api.typesafe.ai/v1'), '/') . '/' . ltrim($path, '/');

        $key = $provider->providerCredentials()['key'] ?? null;

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $headers = $this->mergeConfiguredHeaders($headers, $config['headers'] ?? []);

        $http = HttpClientFactory::create($this->typeSafeHttpTimeout);

        return $http->post($url, json_encode($body) ?: '{}', [
            'headers' => $headers,
        ]);
    }

    /**
     * The status codes that indicate a provider is transiently unavailable and the request should fail over.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [529, 502, 503, 504, 520, 522, 524];
    }
}
