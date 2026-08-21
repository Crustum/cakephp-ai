<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Crustum\Ai\Contracts\Gateway\RerankingGateway;

/**
 * Provides reranking gateway access for AI providers.
 */
trait HasRerankingGatewayTrait
{
    protected RerankingGateway $rerankingGateway;

    /**
     * Get the provider's reranking gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\RerankingGateway
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway;
    }

    /**
     * Set the provider's reranking gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\RerankingGateway $gateway Reranking gateway
     * @return $this
     */
    public function useRerankingGateway(RerankingGateway $gateway)
    {
        $this->rerankingGateway = $gateway;

        return $this;
    }
}
