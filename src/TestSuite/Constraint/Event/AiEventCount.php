<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Event;

use Crustum\Ai\TestSuite\Capture\EventCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts an AI event was dispatched a specific number of times.
 *
 * @internal
 */
class AiEventCount extends Constraint
{
    /**
     * @param class-string<\Crustum\Ai\Event\AiEvent> $eventClass Event class
     */
    public function __construct(protected string $eventClass)
    {
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
