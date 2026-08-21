<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Crustum\Ai\Contracts\Gateway\StoreGateway;

/**
 * Provides store gateway access for AI providers.
 */
trait HasStoreGatewayTrait
{
    protected StoreGateway $storeGateway;

    /**
     * Get the provider's store gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StoreGateway
     */
    public function storeGateway(): StoreGateway
    {
        return $this->storeGateway;
    }

    /**
     * Set the provider's store gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\StoreGateway $gateway Store gateway
     * @return $this
     */
    public function useStoreGateway(StoreGateway $gateway)
    {
        $this->storeGateway = $gateway;

        return $this;
    }
}
