<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\AudioGateway;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Gateway\StoreGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\StoreProvider;
use Crustum\Ai\Contracts\Providers\SupportsFileSearch;
use Crustum\Ai\Contracts\Providers\SupportsToolSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\OpenAi\OpenAiFileGateway;
use Crustum\Ai\Gateway\OpenAi\OpenAiGateway;
use Crustum\Ai\Gateway\OpenAi\OpenAiStoreGateway;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Providers\Trait\GeneratesAudioTrait;
use Crustum\Ai\Providers\Trait\GeneratesEmbeddingsTrait;
use Crustum\Ai\Providers\Trait\GeneratesImagesTrait;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\GeneratesTranscriptionsTrait;
use Crustum\Ai\Providers\Trait\HasAudioGatewayTrait;
use Crustum\Ai\Providers\Trait\HasEmbeddingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasFileGatewayTrait;
use Crustum\Ai\Providers\Trait\HasImageGatewayTrait;
use Crustum\Ai\Providers\Trait\HasStoreGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTranscriptionGatewayTrait;
use Crustum\Ai\Providers\Trait\ManagesFilesTrait;
use Crustum\Ai\Providers\Trait\ManagesStoresTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;
use Crustum\Ai\Utility\Value;

/**
 * OpenAI provider.
 */
class OpenAiProvider extends Provider implements AudioProvider, EmbeddingProvider, FileProvider, ImageProvider, StoreProvider, SupportsFileSearch, SupportsToolSearch, SupportsWebSearch, TextProvider, TranscriptionProvider
{
    use GeneratesAudioTrait;
    use GeneratesEmbeddingsTrait;
    use GeneratesImagesTrait;
    use GeneratesTextTrait;
    use GeneratesTranscriptionsTrait;
    use HasAudioGatewayTrait;
    use HasEmbeddingGatewayTrait;
    use HasFileGatewayTrait;
    use HasImageGatewayTrait;
    use HasStoreGatewayTrait;
    use HasTextGatewayTrait;
    use HasTranscriptionGatewayTrait;
    use ManagesFilesTrait;
    use ManagesStoresTrait;
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
        $this->config['name'] ??= 'openai';
        $this->config['driver'] ??= 'openai';
        $this->config['key'] ??= $this->config['apiKey'] ?? null;
    }

    /**
     * Get the file search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\FileSearch $search File search tool
     * @return array<string, mixed>
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        return array_filter([
            'vector_store_ids' => $search->ids(),
            'filters' => Value::filled($search->filters) ? [
                'type' => 'and',
                'filters' => collection($search->filters)->map(fn(array $filter): array => [
                    'type' => $filter['type'],
                    'key' => $filter['key'],
                    'value' => $filter['value'],
                ])->toList(),
            ] : null,
        ]);
    }

    /**
     * Get the web search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $search Web search tool
     * @return array<string, mixed>
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        $options = $search->providerOptions(Lab::OpenAI);

        $filters = array_merge(
            Value::filled($search->allowedDomains) ? ['allowed_domains' => $search->allowedDomains] : [],
            $options['filters'] ?? [],
        );

        unset($options['filters']);

        return array_filter([
            'filters' => Value::filled($filters) ? $filters : null,
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]) + $options;
    }

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new OpenAiGateway($this->events);
    }

    /**
     * Get the provider's audio gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\AudioGateway
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new OpenAiGateway($this->events);
    }

    /**
     * Get the provider's image gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ImageGateway
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new OpenAiGateway($this->events);
    }

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new OpenAiGateway($this->events);
    }

    /**
     * Get the provider's transcription gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\TranscriptionGateway
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new OpenAiGateway($this->events);
    }

    /**
     * Get the provider's file gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\FileGateway
     */
    public function fileGateway(): FileGateway
    {
        if (!isset($this->fileGateway)) {
            $this->fileGateway = new OpenAiFileGateway();
        }

        return $this->fileGateway;
    }

    /**
     * Get the provider's store gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StoreGateway
     */
    public function storeGateway(): StoreGateway
    {
        if (!isset($this->storeGateway)) {
            $this->storeGateway = new OpenAiStoreGateway();
        }

        return $this->storeGateway;
    }

    /**
     * Get the name of the default text model.
     *
     * @return string
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'gpt-5.4';
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'gpt-5.4-nano';
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'gpt-5.4-pro';
    }

    /**
     * Get the name of the default image model.
     *
     * @return string
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'gpt-image-2';
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
            'size' => match ($size) {
                '1:1' => '1024x1024',
                '2:3' => '1024x1536',
                '3:2' => '1536x1024',
                default => $size,
            },
            'quality' => $quality,
        ]);
    }

    /**
     * Get the name of the default audio (TTS) model.
     *
     * @return string
     */
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'gpt-4o-mini-tts';
    }

    /**
     * Get the name of the default transcription (STT) model.
     *
     * @return string
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'gpt-4o-transcribe-diarize';
    }

    /**
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'text-embedding-3-small';
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
