<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;

/**
 * Provides embedding gateway access for AI providers.
 */
trait HasEmbeddingGatewayTrait
{
    protected ?EmbeddingGateway $embeddingGateway = null;

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ?? $this->gateway;
    }

    /**
     * Set the provider's embedding gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\EmbeddingGateway $gateway Embedding gateway
     * @return $this
     */
    public function useEmbeddingGateway(EmbeddingGateway $gateway)
    {
        $this->embeddingGateway = $gateway;

        return $this;
    }
}
