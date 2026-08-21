<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\AudioGateway;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\SupportsWebFetch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Crustum\Ai\Providers\Tools\WebFetch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Providers\Trait\GeneratesAudioTrait;
use Crustum\Ai\Providers\Trait\GeneratesEmbeddingsTrait;
use Crustum\Ai\Providers\Trait\GeneratesImagesTrait;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\GeneratesTranscriptionsTrait;
use Crustum\Ai\Providers\Trait\HasAudioGatewayTrait;
use Crustum\Ai\Providers\Trait\HasEmbeddingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasImageGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTranscriptionGatewayTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;

/**
 * OpenRouter AI provider.
 */
class OpenRouterProvider extends Provider implements AudioProvider, EmbeddingProvider, ImageProvider, SupportsWebFetch, SupportsWebSearch, TextProvider, TranscriptionProvider
{
    use GeneratesAudioTrait;
    use GeneratesEmbeddingsTrait;
    use GeneratesImagesTrait;
    use GeneratesTextTrait;
    use GeneratesTranscriptionsTrait;
    use HasAudioGatewayTrait;
    use HasEmbeddingGatewayTrait;
    use HasImageGatewayTrait;
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
        $this->config['name'] ??= 'openrouter';
        $this->config['driver'] ??= 'openrouter';
        $this->config['key'] ??= $this->config['apiKey'] ?? null;
    }

    /**
     * Get the web fetch tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebFetch $fetch Web fetch tool
     * @return array<string, mixed>
     */
    public function webFetchToolOptions(WebFetch $fetch): array
    {
        return $this->serverToolOptions($fetch);
    }

    /**
     * Get the web search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $search Web search tool
     * @return array<string, mixed>
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return $this->serverToolOptions($search);
    }

    /**
     * Get the parameters for an OpenRouter server tool.
     *
     * @param \Crustum\Ai\Providers\Tools\WebFetch|\Crustum\Ai\Providers\Tools\WebSearch $tool Web tool
     * @return array<string, mixed>
     */
    protected function serverToolOptions(WebFetch|WebSearch $tool): array
    {
        return array_filter([
            'parameters' => array_filter([
                'max_uses' => $tool->maxSearches,
                'allowed_domains' => $tool->allowedDomains,
            ]) + $tool->providerOptions(Lab::OpenRouter),
        ]);
    }

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new OpenRouterGateway($this->events);
    }

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new OpenRouterGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     *
     * @return string
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? $this->config['models']['text'] ?? 'anthropic/claude-sonnet-4.6';
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'anthropic/claude-haiku-4.5';
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'anthropic/claude-opus-4.6';
    }

    /**
     * Get the provider's image gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ImageGateway
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new OpenRouterGateway($this->events);
    }

    /**
     * Get the name of the default image model.
     *
     * @return string
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? $this->config['models']['image'] ?? 'google/gemini-3.1-flash-image-preview';
    }

    /**
     * Get the default / normalized image options for the provider.
     *
     * @param string|null $size Image size
     * @param 'low'|'medium'|'high'|null $quality Image quality
     * @return array<string, mixed>
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'aspect_ratio' => $size,
            'image_size' => match ($quality) {
                'low' => '1K',
                'medium' => '2K',
                'high' => '4K',
                default => null,
            },
        ]);
    }

    /**
     * Get the provider's audio gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\AudioGateway
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new OpenRouterGateway($this->events);
    }

    /**
     * Get the name of the default audio (TTS) model.
     *
     * @return string
     */
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? $this->config['models']['audio'] ?? 'google/gemini-3.1-flash-tts-preview';
    }

    /**
     * Get the provider's transcription gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\TranscriptionGateway
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new OpenRouterGateway($this->events);
    }

    /**
     * Get the name of the default transcription (STT) model.
     *
     * @return string
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? $this->config['models']['transcription'] ?? 'openai/whisper-1';
    }

    /**
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? $this->config['models']['embeddings'] ?? 'google/gemini-embedding-001';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     *
     * @return int
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1536;
    }
}
