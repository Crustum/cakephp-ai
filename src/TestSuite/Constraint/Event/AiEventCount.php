<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Event;

use Crustum\Ai\Event\AiEvent;
use Crustum\Ai\TestSuite\Capture\EventCapture;
use InvalidArgumentException;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts an AI event was dispatched a specific number of times.
 *
 * @internal
 */
class AiEventCount extends Constraint
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
     * @param mixed $other Expected count
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        return count(EventCapture::eventsOf($this->eventClass)) === (int)$other;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('AI event [%s] count matches expected value', $this->eventClass);
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return sprintf(
            'AI event [%s] count is %d matching expected %d',
            $this->eventClass,
            count(EventCapture::eventsOf($this->eventClass)),
            (int)$other,
        ) . "\n" . EventCapture::timeline();
    }
}
