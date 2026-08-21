<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Gateway\JinaGateway;
use Crustum\Ai\Providers\Trait\GeneratesEmbeddingsTrait;
use Crustum\Ai\Providers\Trait\HasEmbeddingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasRerankingGatewayTrait;
use Crustum\Ai\Providers\Trait\ReranksTrait;
use Override;

/**
 * Jina embeddings and reranking provider.
 */
class JinaProvider extends Provider implements EmbeddingProvider, RerankingProvider
{
    use GeneratesEmbeddingsTrait;
    use HasEmbeddingGatewayTrait;
    use HasRerankingGatewayTrait;
    use ReranksTrait;

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
     * Shared Jina gateway instance.
     */
    protected ?JinaGateway $jinaGateway = null;

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
        $this->config['name'] ??= 'jina';
        $this->config['driver'] ??= 'jina';
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
     * Get the shared Jina gateway instance.
     *
     * @return \Crustum\Ai\Gateway\JinaGateway
     */
    protected function jinaGateway(): JinaGateway
    {
        return $this->jinaGateway ??= new JinaGateway();
    }

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->jinaGateway();
    }

    /**
     * Get the provider's reranking gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\RerankingGateway
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= $this->jinaGateway();
    }

    /**
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'jina-embeddings-v4';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     *
     * @return int
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 2048;
    }

    /**
     * Get the name of the default reranking model.
     *
     * @return string
     */
    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'jina-reranker-v3';
    }
}
