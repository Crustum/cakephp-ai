<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Crustum\Ai\Contracts\Gateway\ClassificationGateway;

/**
 * Provides classification gateway access for AI providers.
 */
trait HasClassificationGatewayTrait
{
    protected ClassificationGateway $classificationGateway;

    /**
     * Get the provider's classification gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ClassificationGateway
     */
    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway;
    }

    /**
     * Set the provider's classification gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\ClassificationGateway $gateway Classification gateway
     * @return $this
     */
    public function useClassificationGateway(ClassificationGateway $gateway)
    {
        $this->classificationGateway = $gateway;

        return $this;
    }
}
