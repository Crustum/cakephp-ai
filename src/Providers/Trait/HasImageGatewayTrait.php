<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Crustum\Ai\Contracts\Gateway\ImageGateway;

/**
 * Provides image gateway access for AI providers.
 */
trait HasImageGatewayTrait
{
    protected ?ImageGateway $imageGateway = null;

    /**
     * Get the provider's image gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ImageGateway
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ?? $this->gateway;
    }

    /**
     * Set the provider's image gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\ImageGateway $gateway Image gateway
     * @return $this
     */
    public function useImageGateway(ImageGateway $gateway)
    {
        $this->imageGateway = $gateway;

        return $this;
    }
}
