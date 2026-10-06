<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Event;

use Crustum\Ai\Event\AiEvent;
use Crustum\Ai\TestSuite\Capture\EventCapture;
use InvalidArgumentException;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts that an AI event class was dispatched.
 *
 * @internal
 */
class AiEventDispatched extends Constraint
{
    /**
     * @param class-string $eventClass Event class
     * @param callable|null $callback Optional truth test
     */
    public function __construct(
        protected string $eventClass,
        protected $callback = null,
    ) {
        if (!is_subclass_of($eventClass, AiEvent::class)) {
            throw new InvalidArgumentException(sprintf('Expected an Ai event class name, got [%s].', $eventClass));
        }
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        foreach (EventCapture::eventsOf($this->eventClass) as $event) {
            if ($this->callback === null || ($this->callback)($event)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('AI event [%s] was dispatched', $this->eventClass);
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return $this->toString() . "\n" . EventCapture::timeline();
    }
}
