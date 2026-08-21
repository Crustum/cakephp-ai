<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Cake\Event\Event;
use Throwable;

/**
 * Provider Failed Over Event
 *
 * Dispatched when an AI provider fails and the system fails over to another provider.
 */
class ProviderFailedOverEvent extends Event
{
    /**
     * Get the CakePHP event name for this event class.
     *
     * @return string
     */
    public static function eventName(): string
    {
        return 'Ai.providerFailedOver';
    }

    /**
     * Constructor.
     *
     * @param string $from Provider that failed
     * @param string $to Provider failed over to
     * @param \Throwable|null $exception The exception that caused the failover
     */
    public function __construct(string $from, string $to, ?Throwable $exception = null)
    {
        parent::__construct(self::eventName(), null, [
            'from' => $from,
            'to' => $to,
            'exception' => $exception,
        ]);
    }
}
