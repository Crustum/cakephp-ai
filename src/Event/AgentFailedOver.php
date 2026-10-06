<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Exception\FailoverableException;

/**
 * Agent Failed Over Event
 *
 * Dispatched when an agent fails over to the next configured provider.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Contracts\Agent>
 */
class AgentFailedOver extends AiEvent
{
    /**
     * Constructor
     *
     * @param string $invocationId The run invocation identifier
     * @param \Crustum\Ai\Contracts\Agent $agent The agent instance
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider that failed
     * @param string $model The model that failed
     * @param \Crustum\Ai\Exception\FailoverableException $exception The failover exception
     */
    public function __construct(
        public string $invocationId,
        public Agent $agent,
        public Provider $provider,
        public string $model,
        public FailoverableException $exception,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'model' => $model,
            'exception' => $exception,
        ], $agent);
    }
}
