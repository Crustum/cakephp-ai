<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Event;

use Crustum\Ai\Event\AiEvent;
use Crustum\Ai\TestSuite\Capture\EventCapture;
use InvalidArgumentException;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts that an AI event class was not dispatched.
 *
 * @internal
 */
class AiEventNotDispatched extends Constraint
{
    /**
     * @param class-string $eventClass Event class
     */
    public function __construct(protected string $eventClass)
    {
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
        return EventCapture::eventsOf($this->eventClass) === [];
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('AI event [%s] was not dispatched', $this->eventClass);
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
