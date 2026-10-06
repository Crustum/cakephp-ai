<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\CohereGateway;
use Crustum\Ai\Providers\Trait\GeneratesEmbeddingsTrait;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\HasEmbeddingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasRerankingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\ReranksTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;
use Override;

/**
 * Cohere embeddings, reranking, and text provider.
 */
class CohereProvider extends Provider implements EmbeddingProvider, RerankingProvider, TextProvider
{
    use GeneratesEmbeddingsTrait;
    use GeneratesTextTrait;
    use HasEmbeddingGatewayTrait;
    use HasRerankingGatewayTrait;
    use HasTextGatewayTrait;
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
     * Shared Cohere gateway instance.
     */
    protected ?CohereGateway $cohereGateway = null;

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
        $this->config['name'] ??= 'cohere';
        $this->config['driver'] ??= 'cohere';
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
     * Get the shared Cohere gateway instance.
     *
     * @return \Crustum\Ai\Gateway\CohereGateway
     */
    protected function cohereGateway(): CohereGateway
    {
        return $this->cohereGateway ??= new CohereGateway();
    }

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->cohereGateway();
    }

    /**
     * Get the provider's reranking gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\RerankingGateway
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= $this->cohereGateway();
    }

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= $this->cohereGateway();
    }

    /**
     * Get the name of the default text model.
     *
     * @return string
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'command-a-03-2025';
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'command-r7b-12-2024';
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'command-a-plus-05-2026';
    }

    /**
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'embed-v4.0';
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
     * Get the name of the default reranking model.
     *
     * @return string
     */
    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'rerank-v4.0-pro';
    }
}
