<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Gateway\TextGenerationLoop;

/**
 * Provides text gateway access for AI providers.
 */
trait HasTextGatewayTrait
{
    protected ?StepTextGateway $textGateway = null;

    protected ?TextGenerationLoop $textGenerationLoop = null;

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ?? $this->gateway;
    }

    /**
     * Set the provider's text gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\StepTextGateway $gateway Text gateway
     * @return $this
     */
    public function useTextGateway(StepTextGateway $gateway)
    {
        $this->textGateway = $gateway;
        $this->textGenerationLoop = null;

        return $this;
    }

    /**
     * Get the multi-step text generation loop wrapping the provider's text gateway.
     *
     * @return \Crustum\Ai\Gateway\TextGenerationLoop
     */
    public function textGenerationLoop(): TextGenerationLoop
    {
        return $this->textGenerationLoop ??= new TextGenerationLoop($this->textGateway());
    }
}
