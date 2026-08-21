<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;

/**
 * Provides transcription gateway access for AI providers.
 */
trait HasTranscriptionGatewayTrait
{
    protected ?TranscriptionGateway $transcriptionGateway = null;

    /**
     * Get the provider's transcription gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\TranscriptionGateway
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ?? $this->gateway;
    }

    /**
     * Set the provider's transcription gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\TranscriptionGateway $gateway Transcription gateway
     * @return $this
     */
    public function useTranscriptionGateway(TranscriptionGateway $gateway)
    {
        $this->transcriptionGateway = $gateway;

        return $this;
    }
}
