<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Crustum\Ai\Contracts\Gateway\AudioGateway;

/**
 * Provides audio gateway access for AI providers.
 */
trait HasAudioGatewayTrait
{
    protected ?AudioGateway $audioGateway = null;

    /**
     * Get the provider's audio gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\AudioGateway
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ?? $this->gateway;
    }

    /**
     * Set the provider's audio gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\AudioGateway $gateway Audio gateway
     * @return $this
     */
    public function useAudioGateway(AudioGateway $gateway)
    {
        $this->audioGateway = $gateway;

        return $this;
    }
}
