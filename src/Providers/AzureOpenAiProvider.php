<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Gateway\StoreGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\StoreProvider;
use Crustum\Ai\Contracts\Providers\SupportsCodeExecution;
use Crustum\Ai\Contracts\Providers\SupportsFileSearch;
use Crustum\Ai\Contracts\Providers\SupportsToolSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\AzureOpenAi\AzureOpenAiFileGateway;
use Crustum\Ai\Gateway\AzureOpenAi\AzureOpenAiGateway;
use Crustum\Ai\Gateway\AzureOpenAi\AzureOpenAiStoreGateway;
use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Providers\Trait\GeneratesEmbeddingsTrait;
use Crustum\Ai\Providers\Trait\GeneratesImagesTrait;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\HasEmbeddingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasFileGatewayTrait;
use Crustum\Ai\Providers\Trait\HasImageGatewayTrait;
use Crustum\Ai\Providers\Trait\HasStoreGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\ManagesFilesTrait;
use Crustum\Ai\Providers\Trait\ManagesStoresTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;
use Crustum\Ai\Utility\Value;
use InvalidArgumentException;
use Override;

/**
 * Azure OpenAI text, image, embeddings, files, and stores provider.
 */
class AzureOpenAiProvider extends Provider implements EmbeddingProvider, FileProvider, ImageProvider, StoreProvider, SupportsCodeExecution, SupportsFileSearch, SupportsToolSearch, SupportsWebSearch, TextProvider
{
    use GeneratesEmbeddingsTrait;
    use GeneratesImagesTrait;
    use GeneratesTextTrait;
    use HasEmbeddingGatewayTrait;
    use HasFileGatewayTrait;
    use HasImageGatewayTrait;
    use HasStoreGatewayTrait;
    use HasTextGatewayTrait;
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
     * Shared Azure OpenAI gateway instance.
     */
    protected ?AzureOpenAiGateway $azureGateway = null;

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
        $this->config['name'] ??= 'azure';
        $this->config['driver'] ??= 'azure';
        $this->config['key'] ??= $this->config['apiKey'] ?? null;
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function additionalConfiguration(): array
    {
        return [
            'url' => rtrim((string)($this->config['url'] ?? ''), '/'),
            'api_version' => $this->config['api_version'] ?? '2025-04-01-preview',
            'store' => $this->config['store'] ?? true,
            'headers' => $this->config['headers'] ?? [],
        ];
    }

    /**
     * Get the shared Azure OpenAI gateway instance.
     *
     * @return \Crustum\Ai\Gateway\AzureOpenAi\AzureOpenAiGateway
     */
    protected function azureGateway(): AzureOpenAiGateway
    {
        return $this->azureGateway ??= new AzureOpenAiGateway($this->events);
    }

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= $this->azureGateway();
    }

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->azureGateway();
    }

    /**
     * Get the provider's image gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ImageGateway
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= $this->azureGateway();
    }

    /**
     * Get the provider's file gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\FileGateway
     */
    public function fileGateway(): FileGateway
    {
        if (!isset($this->fileGateway)) {
            $this->fileGateway = new AzureOpenAiFileGateway();
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
            $this->storeGateway = new AzureOpenAiStoreGateway();
        }

        return $this->storeGateway;
    }

    /**
     * Get the name of the default (deployment name) text model.
     *
     * @return string
     */
    public function defaultTextModel(): string
    {
        return $this->config['deployment'] ?? 'gpt-6-sol';
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['deployment'] ?? 'gpt-6-luna';
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['deployment'] ?? 'gpt-6-astra';
    }

    /**
     * Get the name of the default image deployment.
     *
     * @return string
     */
    public function defaultImageModel(): string
    {
        return $this->config['image_deployment'] ?? 'gpt-image-2.5-flare';
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
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['embedding_deployment'] ?? 'text-embedding-3-small';
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
     * Get the file search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\FileSearch $search File search tool
     * @return array<string, mixed>
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        if (Value::filled($search->filters)) {
            throw new InvalidArgumentException('Azure OpenAI does not support file search metadata filters.');
        }

        return array_filter([
            'vector_store_ids' => $search->ids(),
        ]);
    }

    /**
     * Get the code execution tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\CodeExecution $codeExecution Code execution tool
     * @return array<string, mixed>
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array
    {
        return $codeExecution->providerOptions(Lab::Azure) + [
            'container' => ['type' => 'auto'],
        ];
    }

    /**
     * Get the web search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $search Web search tool
     * @return array<string, mixed>
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        $options = $search->providerOptions(Lab::Azure);

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
}
