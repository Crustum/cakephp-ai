<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Collection\Collection;
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
use Crustum\Ai\Contracts\Providers\SupportsCodeExecution;
use Crustum\Ai\Contracts\Providers\SupportsFileSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebFetch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\Gemini\GeminiFileGateway;
use Crustum\Ai\Gateway\Gemini\GeminiGateway;
use Crustum\Ai\Gateway\Gemini\GeminiStoreGateway;
use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Providers\Tools\WebFetch;
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
use InvalidArgumentException;
use Override;

/**
 * Gemini multi-modal provider.
 */
class GeminiProvider extends Provider implements AudioProvider, EmbeddingProvider, FileProvider, ImageProvider, StoreProvider, SupportsCodeExecution, SupportsFileSearch, SupportsWebFetch, SupportsWebSearch, TextProvider, TranscriptionProvider
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
     * Shared Gemini gateway instance.
     */
    protected ?GeminiGateway $geminiGateway = null;

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
        $this->config['name'] ??= 'gemini';
        $this->config['driver'] ??= 'gemini';
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
     * Get the code execution tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\CodeExecution $codeExecution Code execution tool
     * @return array<string, mixed>
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array
    {
        return $codeExecution->providerOptions(Lab::Gemini);
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
            'file_search_store_names' => $search->ids(),
            'metadata_filter' => $search->filters === []
                ? null
                : $this->formatMetadataFilter($search->filters),
        ]);
    }

    /**
     * Format the file search metadata filter for Gemini's filter expression syntax.
     *
     * @param array<int, array{type: string, key: string, value: mixed}> $filters Metadata filters
     * @return string
     */
    protected function formatMetadataFilter(array $filters): string
    {
        return (new Collection($filters))->map(fn(mixed $filter): string => match ($filter['type']) {
            'eq' => is_numeric($filter['value'])
                ? "{$filter['key']}={$filter['value']}"
                : "{$filter['key']}=\"{$filter['value']}\"",
            'ne' => is_numeric($filter['value'])
                ? "{$filter['key']}!={$filter['value']}"
                : "{$filter['key']}!=\"{$filter['value']}\"",
            'in' => '(' . (new Collection($filter['value']))->map(fn(mixed $v): string => is_numeric($v) ? "{$filter['key']}={$v}" : "{$filter['key']}=\"{$v}\"")->implode(' OR ') . ')',
            default => '',
        })->implode(' AND ');
    }

    /**
     * Get the web fetch tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebFetch $fetch Web fetch tool
     * @return array<string, mixed>
     */
    public function webFetchToolOptions(WebFetch $fetch): array
    {
        return [];
    }

    /**
     * Get the web search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $search Web search tool
     * @return array<string, mixed>
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return [];
    }

    /**
     * Get the shared Gemini gateway instance.
     *
     * @return \Crustum\Ai\Gateway\Gemini\GeminiGateway
     */
    protected function geminiGateway(): GeminiGateway
    {
        return $this->geminiGateway ??= new GeminiGateway($this->events);
    }

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= $this->geminiGateway();
    }

    /**
     * Get the provider's audio gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\AudioGateway
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= $this->geminiGateway();
    }

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->geminiGateway();
    }

    /**
     * Get the provider's image gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ImageGateway
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= $this->geminiGateway();
    }

    /**
     * Get the provider's transcription gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\TranscriptionGateway
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= $this->geminiGateway();
    }

    /**
     * Get the provider's file gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\FileGateway
     */
    public function fileGateway(): FileGateway
    {
        if (!isset($this->fileGateway)) {
            $this->fileGateway = new GeminiFileGateway($this->events);
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
            $this->storeGateway = new GeminiStoreGateway($this->events);
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
        return $this->config['models']['text']['default'] ?? 'gemini-3.8-flash';
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'gemini-3.1-flash-lite';
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'gemini-3.8-flash';
    }

    /**
     * Get the name of the default image model.
     *
     * @return string
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'gemini-3.1-flash-image';
    }

    /**
     * Get the default / normalized image options for the provider.
     *
     * @param string|null $size Image size
     * @param string|null $quality Image quality
     * @return array<string, mixed>
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'image_size' => match ($quality) {
                'low', '1K' => '1K',
                'medium', '2K' => '2K',
                'high', '4K' => '4K',
                default => '1K',
            },
            'aspect_ratio' => match ($size) {
                '1:1' => '1:1',
                '2:3' => '2:3',
                '3:2' => '3:2',
                null => null,
                default => $size,
            },
        ]);
    }

    /**
     * Get the name of the default audio (TTS) model.
     *
     * @return string
     */
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'gemini-3.8-flash-lite-tts';
    }

    /**
     * Get the name of the default transcription (STT) model.
     *
     * @return string
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'gemini-3.5-transcribe';
    }

    /**
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'gemini-embedding-2';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     *
     * @return int
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 3072;
    }

    /**
     * Validate embeddings inputs against Gemini's supported media types.
     *
     * @param array<int, mixed> $inputs Inputs to embed
     * @param string $model Model name
     * @return void
     */
    protected function validateEmbeddingInputs(array $inputs, string $model): void
    {
        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;

        foreach ($inputs as $input) {
            if (is_string($input)) {
                continue;
            }

            if (!$this->isGeminiMultimodalEmbeddingModel($model)) {
                throw new InvalidArgumentException(
                    "Model [{$model}] does not support Gemini multimodal embeddings. Use [gemini-embedding-2] or [gemini-embedding-2-preview].",
                );
            }

            return;
        }
    }

    /**
     * Determine if the given model supports Gemini multimodal embeddings.
     *
     * @param string $model Model name
     * @return bool
     */
    protected function isGeminiMultimodalEmbeddingModel(string $model): bool
    {
        return in_array($model, ['gemini-embedding-2', 'gemini-embedding-2-preview'], true);
    }
}
