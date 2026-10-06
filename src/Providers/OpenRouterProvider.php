<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\AudioGateway;
use Crustum\Ai\Contracts\Gateway\ClassificationGateway;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\ClassificationProvider;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Contracts\Providers\SupportsWebFetch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\OpenRouter\OpenRouterClassificationGateway;
use Crustum\Ai\Gateway\OpenRouter\OpenRouterFileGateway;
use Crustum\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Crustum\Ai\Providers\Tools\WebFetch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Providers\Trait\ClassifiesTrait;
use Crustum\Ai\Providers\Trait\GeneratesAudioTrait;
use Crustum\Ai\Providers\Trait\GeneratesEmbeddingsTrait;
use Crustum\Ai\Providers\Trait\GeneratesImagesTrait;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\GeneratesTranscriptionsTrait;
use Crustum\Ai\Providers\Trait\HasAudioGatewayTrait;
use Crustum\Ai\Providers\Trait\HasClassificationGatewayTrait;
use Crustum\Ai\Providers\Trait\HasEmbeddingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasFileGatewayTrait;
use Crustum\Ai\Providers\Trait\HasImageGatewayTrait;
use Crustum\Ai\Providers\Trait\HasRerankingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTranscriptionGatewayTrait;
use Crustum\Ai\Providers\Trait\ManagesFilesTrait;
use Crustum\Ai\Providers\Trait\ReranksTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;

/**
 * OpenRouter AI provider.
 */
class OpenRouterProvider extends Provider implements AudioProvider, ClassificationProvider, EmbeddingProvider, FileProvider, ImageProvider, RerankingProvider, SupportsWebFetch, SupportsWebSearch, TextProvider, TranscriptionProvider
{
    use ClassifiesTrait;
    use GeneratesAudioTrait;
    use GeneratesEmbeddingsTrait;
    use GeneratesImagesTrait;
    use GeneratesTextTrait;
    use GeneratesTranscriptionsTrait;
    use HasAudioGatewayTrait;
    use HasClassificationGatewayTrait;
    use HasEmbeddingGatewayTrait;
    use HasFileGatewayTrait;
    use HasImageGatewayTrait;
    use HasRerankingGatewayTrait;
    use HasTextGatewayTrait;
    use HasTranscriptionGatewayTrait;
    use ManagesFilesTrait;
    use ReranksTrait;
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
        return $this->serverToolOptions($search, [
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]);
    }

    /**
     * Get the parameters for an OpenRouter server tool.
     *
     * @param \Crustum\Ai\Providers\Tools\WebFetch|\Crustum\Ai\Providers\Tools\WebSearch $tool Web tool
     * @param array<string, mixed> $parameters Additional tool parameters
     * @return array<string, mixed>
     */
    protected function serverToolOptions(WebFetch|WebSearch $tool, array $parameters = []): array
    {
        return array_filter([
            'parameters' => array_filter([
                'max_uses' => $tool->maxSearches,
                'allowed_domains' => $tool->allowedDomains,
                ...$parameters,
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
        return $this->config['models']['text']['default'] ?? $this->config['models']['text'] ?? 'anthropic/claude-sonnet-5.5';
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
        return $this->config['models']['text']['smartest'] ?? 'anthropic/claude-fable-5.1';
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
        return $this->config['models']['image']['default'] ?? $this->config['models']['image'] ?? 'google/gemini-3.1-flash-image';
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
        return $this->config['models']['audio']['default'] ?? $this->config['models']['audio'] ?? 'google/gemini-3.8-flash-lite-tts';
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
        return $this->config['models']['transcription']['default'] ?? $this->config['models']['transcription'] ?? 'openai/gpt-transcribe';
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

    /**
     * Get the provider's file gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\FileGateway
     */
    public function fileGateway(): FileGateway
    {
        if (!isset($this->fileGateway)) {
            $this->fileGateway = new OpenRouterFileGateway();
        }

        return $this->fileGateway;
    }

    /**
     * Get the provider's classification gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ClassificationGateway
     */
    public function classificationGateway(): ClassificationGateway
    {
        if (!isset($this->classificationGateway)) {
            $this->classificationGateway = new OpenRouterClassificationGateway();
        }

        return $this->classificationGateway;
    }

    /**
     * Get the name of the default classification model.
     *
     * @return string
     */
    public function defaultClassificationModel(): string
    {
        return $this->config['models']['classification']['default'] ?? '~typesafe/jev-latest';
    }

    /**
     * Get the provider's reranking gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\RerankingGateway
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new OpenRouterGateway($this->events);
    }

    /**
     * Get the name of the default reranking model.
     *
     * @return string
     */
    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'cohere/rerank-4-pro';
    }
}
