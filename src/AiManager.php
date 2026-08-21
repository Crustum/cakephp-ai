<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Cake\Core\Configure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Contracts\Providers\StoreProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\FakeAudioGateway;
use Crustum\Ai\Gateway\FakeEmbeddingGateway;
use Crustum\Ai\Gateway\FakeFileGateway;
use Crustum\Ai\Gateway\FakeImageGateway;
use Crustum\Ai\Gateway\FakeRerankingGateway;
use Crustum\Ai\Gateway\FakeStoreGateway;
use Crustum\Ai\Gateway\FakeTranscriptionGateway;
use Crustum\Ai\Registry\ProviderRegistry;
use Crustum\Ai\Trait\InteractsWithFakeAgentsTrait;
use Crustum\Ai\Trait\InteractsWithFakeAudioTrait;
use Crustum\Ai\Trait\InteractsWithFakeEmbeddingsTrait;
use Crustum\Ai\Trait\InteractsWithFakeFilesTrait;
use Crustum\Ai\Trait\InteractsWithFakeImagesTrait;
use Crustum\Ai\Trait\InteractsWithFakeRerankingTrait;
use Crustum\Ai\Trait\InteractsWithFakeStoresTrait;
use Crustum\Ai\Trait\InteractsWithFakeTranscriptionsTrait;
use InvalidArgumentException;
use LogicException;

/**
 * AI Manager
 *
 * Central manager for AI providers and capabilities.
 * Provides access to configured AI providers and their functionality.
 */
class AiManager
{
    use InteractsWithFakeAgentsTrait;
    use InteractsWithFakeAudioTrait;
    use InteractsWithFakeEmbeddingsTrait;
    use InteractsWithFakeFilesTrait;
    use InteractsWithFakeImagesTrait;
    use InteractsWithFakeRerankingTrait;
    use InteractsWithFakeStoresTrait;
    use InteractsWithFakeTranscriptionsTrait;

    /**
     * Default provider name
     */
    protected ?string $defaultProvider = null;

    /**
     * Conversation store instance
     */
    protected ?ConversationStore $conversationStore = null;

    /**
     * Constructor
     *
     * @param \Crustum\Ai\Registry\ProviderRegistry $registry Provider registry instance
     */
    public function __construct(protected ProviderRegistry $registry)
    {
    }

    /**
     * Get a provider instance by name
     *
     * @param string|null $name Provider name, or null for default provider
     * @return object Provider instance
     */
    public function provider(?string $name = null): object
    {
        $name ??= $this->getDefaultProvider();

        return $this->registry->load($name);
    }

    /**
     * Get the default provider name
     *
     * @return string Default provider name
     */
    public function getDefaultProvider(): string
    {
        if ($this->defaultProvider !== null) {
            return $this->defaultProvider;
        }

        $default = Configure::read('Ai.defaultProvider', 'openrouter');

        if ($default instanceof Lab) {
            return $default->value;
        }

        if (is_array($default)) {
            throw new InvalidArgumentException('The "Ai.defaultProvider" config value must be a string provider name or a Lab enum, not an array.');
        }

        return (string)$default;
    }

    /**
     * Set the default provider name
     *
     * @param string $name Provider name
     */
    public function setDefaultProvider(string $name): static
    {
        $this->defaultProvider = $name;

        return $this;
    }

    /**
     * Get an audio provider instance by name
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\AudioProvider
     * @throws \LogicException
     */
    public function audioProvider(?string $name = null): AudioProvider
    {
        $instance = $this->provider($name);

        if (!$instance instanceof AudioProvider) {
            throw new LogicException('Provider [' . $instance::class . '] does not support audio generation.');
        }

        return $instance;
    }

    /**
     * Get an audio provider instance, using a fake gateway if audio is faked
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\AudioProvider
     * @throws \LogicException
     */
    public function fakeableAudioProvider(?string $name = null): AudioProvider
    {
        $provider = $this->audioProvider($name);

        if ($this->audioIsFaked()) {
            $gateway = $this->fakeAudioGateway();
            if (!$gateway instanceof FakeAudioGateway) {
                throw new LogicException('Fake audio gateway is not initialized.');
            }

            return (clone $provider)->useAudioGateway($gateway);
        }

        return $provider;
    }

    /**
     * Get an embedding provider instance by name
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\EmbeddingProvider
     * @throws \LogicException
     */
    public function embeddingProvider(?string $name = null): EmbeddingProvider
    {
        $instance = $this->provider($name);

        if (!$instance instanceof EmbeddingProvider) {
            throw new LogicException('Provider [' . $instance::class . '] does not support embedding generation.');
        }

        return $instance;
    }

    /**
     * Get an embedding provider instance, using a fake gateway if embeddings are faked
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\EmbeddingProvider
     * @throws \LogicException
     */
    public function fakeableEmbeddingProvider(?string $name = null): EmbeddingProvider
    {
        $provider = $this->embeddingProvider($name);

        if ($this->embeddingsAreFaked()) {
            $gateway = $this->fakeEmbeddingGateway();
            if (!$gateway instanceof FakeEmbeddingGateway) {
                throw new LogicException('Fake embedding gateway is not initialized.');
            }

            return (clone $provider)->useEmbeddingGateway($gateway);
        }

        return $provider;
    }

    /**
     * Get an image provider instance by name
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\ImageProvider
     * @throws \LogicException
     */
    public function imageProvider(?string $name = null): ImageProvider
    {
        $instance = $this->provider($name);

        if (!$instance instanceof ImageProvider) {
            throw new LogicException('Provider [' . $instance::class . '] does not support image generation.');
        }

        return $instance;
    }

    /**
     * Get an image provider instance, using a fake gateway if images are faked
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\ImageProvider
     * @throws \LogicException
     */
    public function fakeableImageProvider(?string $name = null): ImageProvider
    {
        $provider = $this->imageProvider($name);

        if ($this->imagesAreFaked()) {
            $gateway = $this->fakeImageGateway();
            if (!$gateway instanceof FakeImageGateway) {
                throw new LogicException('Fake image gateway is not initialized.');
            }

            return (clone $provider)->useImageGateway($gateway);
        }

        return $provider;
    }

    /**
     * Get a text provider instance by name
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\TextProvider
     * @throws \LogicException
     */
    public function textProvider(?string $name = null): TextProvider
    {
        $instance = $this->provider($name);

        if (!$instance instanceof TextProvider) {
            throw new LogicException('Provider [' . $instance::class . '] does not support text generation.');
        }

        return $instance;
    }

    /**
     * Get a text provider instance for an agent by name
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\TextProvider
     * @throws \LogicException
     */
    public function textProviderFor(Agent $agent, ?string $name = null): TextProvider
    {
        $provider = $this->textProvider($name);

        if ($this->hasFakeGatewayFor($agent)) {
            $gateway = $this->fakeGatewayFor($agent);

            return (clone $provider)->useTextGateway($gateway);
        }

        return $provider;
    }

    /**
     * Get a transcription provider instance by name
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\TranscriptionProvider
     * @throws \LogicException
     */
    public function transcriptionProvider(?string $name = null): TranscriptionProvider
    {
        $instance = $this->provider($name);

        if (!$instance instanceof TranscriptionProvider) {
            throw new LogicException('Provider [' . $instance::class . '] does not support transcription generation.');
        }

        return $instance;
    }

    /**
     * Get a transcription provider instance, using a fake gateway if transcriptions are faked
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\TranscriptionProvider
     * @throws \LogicException
     */
    public function fakeableTranscriptionProvider(?string $name = null): TranscriptionProvider
    {
        $provider = $this->transcriptionProvider($name);

        if ($this->transcriptionsAreFaked()) {
            $gateway = $this->fakeTranscriptionGateway();
            if (!$gateway instanceof FakeTranscriptionGateway) {
                throw new LogicException('Fake transcription gateway is not initialized.');
            }

            return (clone $provider)->useTranscriptionGateway($gateway);
        }

        return $provider;
    }

    /**
     * Get a reranking provider instance by name
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\RerankingProvider
     * @throws \LogicException
     */
    public function rerankingProvider(?string $name = null): RerankingProvider
    {
        $instance = $this->provider($name);

        if (!$instance instanceof RerankingProvider) {
            throw new LogicException('Provider [' . $instance::class . '] does not support reranking.');
        }

        return $instance;
    }

    /**
     * Get a reranking provider instance, using a fake gateway if reranking is faked
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\RerankingProvider
     * @throws \LogicException
     */
    public function fakeableRerankingProvider(?string $name = null): RerankingProvider
    {
        $provider = $this->rerankingProvider($name);

        if ($this->rerankingIsFaked()) {
            $gateway = $this->fakeRerankingGateway();
            if (!$gateway instanceof FakeRerankingGateway) {
                throw new LogicException('Fake reranking gateway is not initialized.');
            }

            return (clone $provider)->useRerankingGateway($gateway);
        }

        return $provider;
    }

    /**
     * Get a file provider instance by name
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\FileProvider
     * @throws \LogicException
     */
    public function fileProvider(?string $name = null): FileProvider
    {
        $instance = $this->provider($this->resolveProviderName($name, 'default_for_files'));

        if (!$instance instanceof FileProvider) {
            throw new LogicException('Provider [' . $instance::class . '] does not support file management.');
        }

        return $instance;
    }

    /**
     * Get a file provider instance, using a fake gateway if files are faked
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\FileProvider
     * @throws \LogicException
     */
    public function fakeableFileProvider(?string $name = null): FileProvider
    {
        $provider = $this->fileProvider($name);

        if ($this->filesAreFaked()) {
            $gateway = $this->fakeFileGateway();
            if (!$gateway instanceof FakeFileGateway) {
                throw new LogicException('Fake file gateway is not initialized.');
            }

            return (clone $provider)->useFileGateway($gateway);
        }

        return $provider;
    }

    /**
     * Get a store provider instance by name
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\StoreProvider
     * @throws \LogicException
     */
    public function storeProvider(?string $name = null): StoreProvider
    {
        $instance = $this->provider($this->resolveProviderName($name, 'default_for_stores'));

        if (!$instance instanceof StoreProvider) {
            throw new LogicException('Provider [' . $instance::class . '] does not support store management.');
        }

        return $instance;
    }

    /**
     * Get a store provider instance, using a fake gateway if stores are faked
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\StoreProvider
     * @throws \LogicException
     */
    public function fakeableStoreProvider(?string $name = null): StoreProvider
    {
        $provider = $this->storeProvider($name);

        if ($this->storesAreFaked()) {
            $gateway = $this->fakeStoreGateway();
            if (!$gateway instanceof FakeStoreGateway) {
                throw new LogicException('Fake store gateway is not initialized.');
            }

            return (clone $provider)->useStoreGateway($gateway);
        }

        return $provider;
    }

    /**
     * Clear all loaded provider instances
     *
     * @return void
     */
    public function clearInstances(): void
    {
        $this->registry->clear();
    }

    /**
     * Register a pre-built provider instance for tests.
     *
     * @param string $name Provider name
     * @param object $provider Provider instance
     */
    public function registerProviderInstance(string $name, object $provider): static
    {
        $this->clearInstances();
        $this->registry->registerInstance($name, $provider);

        return $this;
    }

    /**
     * Register multiple pre-built provider instances for tests.
     *
     * @param array<string, object> $providers Provider instances keyed by name
     */
    public function registerProviderInstances(array $providers): static
    {
        $this->clearInstances();

        foreach ($providers as $name => $provider) {
            $this->registry->registerInstance($name, $provider);
        }

        return $this;
    }

    /**
     * Reset fake gateways and recorded prompts between tests.
     */
    public function resetFakeState(): self
    {
        $this->fakeAgentGateways = [];
        $this->recordedPrompts = [];
        $this->recordedQueuedPrompts = [];
        $this->fakeAudioGateway = null;
        $this->recordedAudioGenerations = [];
        $this->recordedQueuedAudioGenerations = [];
        $this->fakeEmbeddingGateway = null;
        $this->recordedEmbeddingsGenerations = [];
        $this->recordedQueuedEmbeddingsGenerations = [];
        $this->fakeFileGateway = null;
        $this->recordedFileUploads = [];
        $this->recordedFileDeletions = [];
        $this->fakeImageGateway = null;
        $this->recordedImageGenerations = [];
        $this->recordedQueuedImageGenerations = [];
        $this->fakeRerankingGateway = null;
        $this->recordedRerankings = [];
        $this->fakeStoreGateway = null;
        $this->recordedStoreCreations = [];
        $this->recordedStoreDeletions = [];
        $this->recordedFileAdditions = [];
        $this->recordedFileRemovals = [];
        $this->fakeTranscriptionGateway = null;
        $this->recordedTranscriptionGenerations = [];
        $this->recordedQueuedTranscriptionGenerations = [];
        $this->clearInstances();

        return $this;
    }

    /**
     * Get the configured conversation store.
     *
     * @return \Crustum\Ai\Contracts\ConversationStore
     * @throws \LogicException
     */
    public function conversationStore(): ConversationStore
    {
        if ($this->conversationStore instanceof ConversationStore) {
            return $this->conversationStore;
        }

        $class = Configure::read('Ai.conversationStore');

        if (!is_string($class) || $class === '' || !class_exists($class)) {
            throw new LogicException(
                'Conversation store is not configured. Set Ai.conversationStore to a ConversationStore implementation class name.',
            );
        }

        $store = new $class();

        if (!$store instanceof ConversationStore) {
            throw new LogicException(sprintf(
                'Configured conversation store [%s] must implement %s.',
                $class,
                ConversationStore::class,
            ));
        }

        return $this->conversationStore = $store;
    }

    /**
     * Resolve a provider name for a specific capability.
     *
     * @param string|null $name Explicit provider name
     * @param string $capabilityKey Configure key for the capability default
     * @return string
     */
    protected function resolveProviderName(?string $name, string $capabilityKey): string
    {
        if ($name !== null) {
            return $name;
        }

        $configured = Configure::read('Ai.' . $capabilityKey);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->getDefaultProvider();
    }

    /**
     * Set the conversation store instance.
     *
     * @param \Crustum\Ai\Contracts\ConversationStore $store Conversation store
     */
    public function setConversationStore(ConversationStore $store): static
    {
        $this->conversationStore = $store;

        return $this;
    }
}
