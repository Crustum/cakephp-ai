<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Cake\Core\Configure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\ClassificationProvider;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Contracts\Providers\StoreProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\FakeAudioGateway;
use Crustum\Ai\Gateway\FakeClassificationGateway;
use Crustum\Ai\Gateway\FakeEmbeddingGateway;
use Crustum\Ai\Gateway\FakeFileGateway;
use Crustum\Ai\Gateway\FakeImageGateway;
use Crustum\Ai\Gateway\FakeRerankingGateway;
use Crustum\Ai\Gateway\FakeStoreGateway;
use Crustum\Ai\Gateway\FakeTranscriptionGateway;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Registry\ProviderRegistry;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Trait\InteractsWithFakeAgentsTrait;
use Crustum\Ai\Trait\InteractsWithFakeAudioTrait;
use Crustum\Ai\Trait\InteractsWithFakeClassificationTrait;
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
    use InteractsWithFakeClassificationTrait;
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
     * On-demand provider configurations registered at runtime via build(), keyed by provider name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $onDemandProviders = [];

    /**
     * Get a provider instance by name
     *
     * @param string|null $name Provider name, or null for default provider
     * @return object Provider instance
     */
    public function provider(?string $name = null): object
    {
        $name ??= $this->getDefaultProvider();

        if (!isset($this->onDemandProviders[$name]) && str_starts_with($name, 'ondemand_')) {
            throw new InvalidArgumentException("On-demand provider [{$name}] was not built in this process. Build it where the work runs, such as the agent's provider() method.");
        }

        if (isset($this->onDemandProviders[$name]) && !$this->registry->has($name)) {
            $this->registry->registerInstance($name, $this->instantiateOnDemand($name));
        }

        return $this->registry->load($name);
    }

    /**
     * Instantiate an on-demand provider from its stored configuration.
     *
     * @param string $name On-demand provider name
     * @return \Crustum\Ai\Providers\Provider
     */
    protected function instantiateOnDemand(string $name): Provider
    {
        $config = $this->onDemandProviders[$name];
        $className = $config['className'];

        return new $className($config);
    }

    /**
     * Build an on-demand provider instance from the given configuration.
     *
     * @param array<string, mixed> $config Provider configuration with a driver matching a configured provider (or an explicit className)
     * @return \Crustum\Ai\Providers\Provider
     * @throws \InvalidArgumentException
     */
    public function build(array $config): Provider
    {
        $driver = $config['driver'] ?? null;

        if ($driver instanceof Lab) {
            $driver = $driver->value;
            $config['driver'] = $driver;
        }

        $name = $config['name'] ?? 'ondemand_' . md5(json_encode($config, JSON_THROW_ON_ERROR));

        if (Configure::read('Ai.providers.' . $name) !== null || Lab::tryFrom($name) !== null) {
            throw new InvalidArgumentException("The provider name [{$name}] is already taken.");
        }

        $className = $config['className'] ?? ($driver !== null ? Configure::read('Ai.providers.' . $driver . '.className') : null);

        if (!is_string($className) || !is_subclass_of($className, Provider::class)) {
            throw new InvalidArgumentException(sprintf('Cannot build an on-demand provider for driver [%s]. Use a driver matching a configured provider or pass [className].', (string)$driver));
        }

        $config['driver'] ??= $name;

        $this->onDemandProviders[$name] = [...$config, 'name' => $name, 'className' => $className, 'ondemand' => true];

        $instance = $this->instantiateOnDemand($name);
        $this->registry->registerInstance($name, $instance);

        return $instance;
    }

    /**
     * Flush the on-demand providers built during the current operation.
     *
     * Long-lived queue workers should call this between jobs so tenant
     * configurations do not leak across jobs. Separate worker processes
     * start with an empty on-demand state by construction.
     *
     * @return void
     */
    public function flushState(): void
    {
        foreach (array_keys($this->onDemandProviders) as $name) {
            $this->registry->unload($name);
        }

        $this->onDemandProviders = [];
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
        return $this->ensureProviderSupports(AudioProvider::class, 'audio generation', $name);
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
     * Get a classification provider instance by name
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\ClassificationProvider
     * @throws \LogicException
     */
    public function classificationProvider(?string $name = null): ClassificationProvider
    {
        return $this->ensureProviderSupports(ClassificationProvider::class, 'classification', $name);
    }

    /**
     * Get a classification provider instance, using a fake gateway if classification is faked
     *
     * @param string|null $name Provider name
     * @return \Crustum\Ai\Contracts\Providers\ClassificationProvider
     * @throws \LogicException
     */
    public function fakeableClassificationProvider(?string $name = null): ClassificationProvider
    {
        $provider = $this->classificationProvider($name);

        if ($this->classificationIsFaked()) {
            $gateway = $this->fakeClassificationGateway();
            if (!$gateway instanceof FakeClassificationGateway) {
                throw new LogicException('Fake classification gateway is not initialized.');
            }

            return (clone $provider)->useClassificationGateway($gateway);
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
        return $this->ensureProviderSupports(EmbeddingProvider::class, 'embedding generation', $name);
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
        return $this->ensureProviderSupports(ImageProvider::class, 'image generation', $name);
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
        return $this->ensureProviderSupports(TextProvider::class, 'text generation', $name);
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
        return $this->ensureProviderSupports(TranscriptionProvider::class, 'transcription generation', $name);
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
        return $this->ensureProviderSupports(RerankingProvider::class, 'reranking', $name);
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
        return $this->ensureProviderSupports(FileProvider::class, 'file management', $name, 'default_for_files');
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
        return $this->ensureProviderSupports(StoreProvider::class, 'store management', $name, 'default_for_stores');
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
        $this->fakeClassificationGateway = null;
        $this->recordedClassifications = [];
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
        $this->flushState();
        $this->clearInstances();

        return $this;
    }

    /**
     * Get the conversation store.
     *
     * @return \Crustum\Ai\Contracts\ConversationStore
     */
    public function conversationStore(): ConversationStore
    {
        if ($this->conversationStore instanceof ConversationStore) {
            return $this->conversationStore;
        }

        if (Ai::hasContainer() && Ai::container()->has(ConversationStore::class)) {
            $store = Ai::container()->get(ConversationStore::class);
            if ($store instanceof ConversationStore) {
                return $this->conversationStore = $store;
            }
        }

        return $this->conversationStore = new DatabaseConversationStore(
            Configure::read('Ai.conversations.connection'),
        );
    }

    /**
     * Get a provider instance by name, ensuring it implements the given capability contract.
     *
     * @template TProvider
     * @param class-string<TProvider> $contract Capability contract
     * @param string $capability Capability name for the exception message
     * @param string|null $name Provider name
     * @param string|null $capabilityKey Configure key for the capability default
     * @return TProvider
     * @throws \LogicException
     */
    protected function ensureProviderSupports(
        string $contract,
        string $capability,
        ?string $name,
        ?string $capabilityKey = null,
    ): mixed {
        $resolved = $capabilityKey !== null ? $this->resolveProviderName($name, $capabilityKey) : $name;
        $instance = $this->provider($resolved);

        if (!$instance instanceof $contract) {
            throw new LogicException('Provider [' . $instance::class . '] does not support ' . $capability . '.');
        }

        return $instance;
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
