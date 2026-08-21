<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Stream;

use Crustum\Ai\TestSuite\Capture\StreamCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts stream events were emitted in order as a subsequence.
 *
 * @internal
 */
class StreamEventSequence extends Constraint
{
    /**
     * @param list<class-string<\Crustum\Ai\Streaming\Event\StreamEvent>> $eventClasses Ordered event classes
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

        foreach (StreamCapture::events() as $event) {
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
        return 'stream events were emitted in the expected order';
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return $this->toString()
            . "\nExpected: " . implode(' -> ', $this->eventClasses)
            . "\n" . StreamCapture::timeline();
    }
}
