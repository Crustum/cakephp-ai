<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Providers\Provider;
use Throwable;

/**
 * Provider Failed Over Event
 *
 * Dispatched when an AI provider fails and the system fails over to another provider.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Contracts\Providers\Provider>
 */
class ProviderFailedOver extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $from Provider that failed
     * @param string $to Provider failed over to
     * @param \Throwable|null $exception The exception that caused the failover
     * @param \Crustum\Ai\Contracts\Providers\Provider|null $provider Provider that failed
     */
    public function __construct(string $from, string $to, ?Throwable $exception = null, ?Provider $provider = null)
    {
        parent::__construct([
            'from' => $from,
            'to' => $to,
            'exception' => $exception,
        ], $provider);
    }
}
