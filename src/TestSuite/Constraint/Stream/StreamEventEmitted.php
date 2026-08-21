<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Stream;

use Crustum\Ai\TestSuite\Capture\StreamCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts that a stream event class was emitted.
 *
 * @internal
 */
class StreamEventEmitted extends Constraint
{
    /**
     * @param class-string<\Crustum\Ai\Streaming\Event\StreamEvent> $eventClass Event class
     * @param callable|null $callback Optional truth test
     */
    public function __construct(
        protected string $eventClass,
        protected $callback = null,
    ) {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        foreach (StreamCapture::eventsOf($this->eventClass) as $event) {
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
        return sprintf('stream event [%s] was emitted', $this->eventClass);
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return $this->toString() . "\n" . StreamCapture::timeline();
    }
}
