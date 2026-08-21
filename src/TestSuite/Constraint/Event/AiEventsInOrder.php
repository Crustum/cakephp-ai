<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Event;

use Crustum\Ai\TestSuite\Capture\EventCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts AI events were dispatched in order (as a subsequence).
 *
 * @internal
 */
class AiEventsInOrder extends Constraint
{
    /**
     * @param list<class-string<\Crustum\Ai\Event\AiEvent>> $eventClasses Ordered event classes
     */
    public function __construct(protected array $eventClasses)
    {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        $remaining = $this->eventClasses;

        foreach (EventCapture::events() as $event) {
            if ($remaining === []) {
                break;
            }

            if ($event instanceof $remaining[0]) {
                array_shift($remaining);
            }
        }

        return $remaining === [];
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return 'AI events were dispatched in the expected order';
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return $this->toString()
            . "\nExpected: " . implode(' -> ', $this->eventClasses)
            . "\n" . EventCapture::timeline();
    }
}
