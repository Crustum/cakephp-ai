<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Contracts\Providers\SupportsCodeExecution;
use Crustum\Ai\Contracts\Providers\SupportsToolSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebFetch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\Anthropic\AnthropicFileGateway;
use Crustum\Ai\Gateway\Anthropic\AnthropicGateway;
use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Providers\Tools\WebFetch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\HasFileGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\ManagesFilesTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;
use Override;

/**
 * Anthropic text and file provider.
 */
class AnthropicProvider extends Provider implements FileProvider, SupportsCodeExecution, SupportsToolSearch, SupportsWebFetch, SupportsWebSearch, TextProvider
{
    use GeneratesTextTrait;
    use HasFileGatewayTrait;
    use HasTextGatewayTrait;
    use ManagesFilesTrait;
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
     * Shared Anthropic gateway instance.
     */
    protected ?AnthropicGateway $anthropicGateway = null;

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
        $this->config['name'] ??= 'anthropic';
        $this->config['driver'] ??= 'anthropic';
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
        return $codeExecution->providerOptions(Lab::Anthropic);
    }

    /**
     * Get the web fetch tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebFetch $fetch Web fetch tool
     * @return array<string, mixed>
     */
    public function webFetchToolOptions(WebFetch $fetch): array
    {
        return array_filter([
            'max_uses' => $fetch->maxSearches,
            'allowed_domains' => $fetch->allowedDomains === []
                ? null
                : $fetch->allowedDomains,
        ]) + $fetch->providerOptions(Lab::Anthropic);
    }

    /**
     * Get the web search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $search Web search tool
     * @return array<string, mixed>
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return array_filter([
            'max_uses' => $search->maxSearches,
            'allowed_domains' => $search->allowedDomains === []
                ? null
                : $search->allowedDomains,
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]) + $search->providerOptions(Lab::Anthropic);
    }

    /**
     * Get the shared Anthropic gateway instance.
     *
     * @return \Crustum\Ai\Gateway\Anthropic\AnthropicGateway
     */
    protected function anthropicGateway(): AnthropicGateway
    {
        return $this->anthropicGateway ??= new AnthropicGateway($this->events);
    }

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= $this->anthropicGateway();
    }

    /**
     * Get the provider's file gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\FileGateway
     */
    public function fileGateway(): FileGateway
    {
        if (!isset($this->fileGateway)) {
            $this->fileGateway = new AnthropicFileGateway($this->events);
        }

        return $this->fileGateway;
    }

    /**
     * Get the name of the default text model.
     *
     * @return string
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'claude-sonnet-5-5';
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'claude-haiku-4-5-20251001';
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'claude-fable-5-1';
    }
}
