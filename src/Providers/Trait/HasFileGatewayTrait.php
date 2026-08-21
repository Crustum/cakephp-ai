<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Crustum\Ai\Contracts\Gateway\FileGateway;

/**
 * Provides file gateway access for AI providers.
 */
trait HasFileGatewayTrait
{
    protected FileGateway $fileGateway;

    /**
     * Get the provider's file gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\FileGateway
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway;
    }

    /**
     * Set the provider's file gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\FileGateway $gateway File gateway
     * @return $this
     */
    public function useFileGateway(FileGateway $gateway)
    {
        $this->fileGateway = $gateway;

        return $this;
    }
}
