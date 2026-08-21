<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Cake\Event\Event;
use ReflectionClass;

/**
 * Base AI event class for CakePHP event dispatching.
 */
abstract class AiEvent extends Event
{
    /**
     * Constructor.
     *
     * @param array<string, mixed> $data Event payload
     */
    public function __construct(array $data = [])
    {
        parent::__construct(static::eventName(), null, $data);
    }

    /**
     * Get the CakePHP event name for this event class.
     *
     * @return string
     */
    public static function eventName(): string
    {
        $shortName = (new ReflectionClass(static::class))->getShortName();

        return 'Ai.' . lcfirst($shortName);
    }
}
