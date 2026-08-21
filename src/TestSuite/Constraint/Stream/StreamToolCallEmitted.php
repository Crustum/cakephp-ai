<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Stream;

use Crustum\Ai\TestSuite\Capture\StreamCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts a stream tool call event was emitted for a tool name.
 *
 * @internal
 */
class StreamToolCallEmitted extends Constraint
{
    /**
     * @param string $name Tool name
     */
    public function __construct(protected string $name)
    {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        return StreamCapture::hasToolCall($this->name);
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('stream tool call [%s] was emitted', $this->name);
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
