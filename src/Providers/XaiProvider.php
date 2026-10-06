<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\SupportsCodeExecution;
use Crustum\Ai\Contracts\Providers\SupportsFileSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\Xai\XaiGateway;
use Crustum\Ai\Gateway\Xai\XaiImageGateway;
use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Providers\Trait\GeneratesImagesTrait;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\HasImageGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;
use InvalidArgumentException;
use Override;

/**
 * xAI text and image provider.
 */
class XaiProvider extends Provider implements ImageProvider, SupportsCodeExecution, SupportsFileSearch, SupportsWebSearch, TextProvider
{
    use GeneratesImagesTrait;
    use GeneratesTextTrait;
    use HasImageGatewayTrait;
    use HasTextGatewayTrait;
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
        $this->config['name'] ??= 'xai';
        $this->config['driver'] ??= 'xai';
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
        return $codeExecution->providerOptions(Lab::xAI);
    }

    /**
     * Get the file search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\FileSearch $search File search tool
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When file search metadata filters are provided
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        if (filled($search->filters)) {
            throw new InvalidArgumentException('xAI does not support file search metadata filters.');
        }

        return array_filter([
            'vector_store_ids' => $search->ids(),
        ]) + $search->providerOptions(Lab::xAI);
    }

    /**
     * Get the web search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $search Web search tool
     * @return array<string, mixed>
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        $options = $search->providerOptions(Lab::xAI);

        return array_filter([
            'allowed_domains' => filled($search->allowedDomains) ? $search->allowedDomains : null,
        ]) + $options;
    }

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new XaiGateway($this->events);
    }

    /**
     * Get the provider's image gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ImageGateway
     */
    public function imageGateway(): ImageGateway
    {
        $this->imageGateway ??= new XaiImageGateway($this->events);

        return $this->imageGateway;
    }

    /**
     * Get the name of the default text model.
     *
     * @return string
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'grok-4.7';
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'grok-4.20-non-reasoning';
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'grok-4.7';
    }

    /**
     * Get the name of the default image model.
     *
     * @return string
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'grok-imagine-image-2.0';
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
            'aspect_ratio' => match ($size) {
                '1:1' => '1:1',
                '2:3' => '2:3',
                '3:2' => '3:2',
                null => null,
                default => $size,
            },
            'resolution' => match ($quality) {
                'low', '1K' => '1k',
                'medium', '2K' => '2k',
                'high', '4K' => '2k',
                default => null,
            },
        ]);
    }
}
