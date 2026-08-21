<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;
use Crustum\Ai\Providers\Trait\GeneratesEmbeddingsTrait;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\GeneratesTranscriptionsTrait;
use Crustum\Ai\Providers\Trait\HasEmbeddingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTranscriptionGatewayTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;
use InvalidArgumentException;
use Override;

/**
 * OpenAI-compatible text, embeddings, and transcription provider.
 */
class OpenAiCompatibleProvider extends Provider implements EmbeddingProvider, TextProvider, TranscriptionProvider
{
    use GeneratesEmbeddingsTrait;
    use GeneratesTextTrait;
    use GeneratesTranscriptionsTrait;
    use HasEmbeddingGatewayTrait;
    use HasTextGatewayTrait;
    use HasTranscriptionGatewayTrait;
    use StreamsTextTrait;

    /**
     * Provider configuration.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Event manager instance.
     */
    protected EventManagerInterface $events;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Provider configuration
     * @param \Cake\Event\EventManagerInterface|null $events Event manager instance
     */
    public function __construct(
        array $config,
        ?EventManagerInterface $events = null,
    ) {
        $this->config = $config;
        $this->events = $events ?? EventManager::instance();
        $this->config['name'] ??= 'openai-compatible';
        $this->config['driver'] ??= 'openai-compatible';
        $this->config['key'] ??= $this->config['apiKey'] ?? null;
    }

    /**
     * Get the credentials for the underlying AI provider.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function providerCredentials(): array
    {
        return ['key' => $this->config['key'] ?? null];
    }

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new OpenAiCompatibleGateway($this->events);
    }

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new OpenAiCompatibleGateway($this->events);
    }

    /**
     * Get the provider's transcription gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\TranscriptionGateway
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new OpenAiCompatibleGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     *
     * @return string
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? throw new InvalidArgumentException(
            sprintf('The [%s] openai-compatible provider requires a default text model. Set [models.text.default] in its configuration or pass a model explicitly.', $this->name()),
        );
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? $this->defaultTextModel();
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? $this->defaultTextModel();
    }

    /**
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? throw new InvalidArgumentException(
            sprintf('The [%s] openai-compatible provider requires a default embeddings model. Set [models.embeddings.default] in its configuration or pass a model explicitly.', $this->name()),
        );
    }

    /**
     * Get the default dimensions of the default embeddings model.
     *
     * @return int
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 0;
    }

    /**
     * Get the name of the default transcription (STT) model.
     *
     * @return string
     * @throws \InvalidArgumentException When no default transcription model is configured
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? throw new InvalidArgumentException(
            sprintf('The [%s] openai-compatible provider requires a default transcription model. Set [models.transcription.default] in its configuration or pass a model explicitly.', $this->name()),
        );
    }

    /**
     * Determine if the provider may omit dimensions and use a model's native embedding dimensions.
     *
     * @return bool
     */
    protected function supportsNativeEmbeddingDimensions(): bool
    {
        return true;
    }
}
