<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Tool;

use Crustum\Ai\TestSuite\Capture\EventCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts tools were invoked in order by name (as a subsequence).
 *
 * @internal
 */
class ToolsInvokedInOrder extends Constraint
{
    /**
     * @param list<string> $names Ordered tool names
     */
    public function __construct(protected array $names)
    {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        $remaining = $this->names;

        foreach (EventCapture::tools() as $tool) {
            if ($remaining === []) {
                break;
            }

            if ($tool->name === $remaining[0]) {
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
        return 'tools were invoked in the expected order';
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return $this->toString()
            . "\nExpected: " . implode(' -> ', $this->names)
            . "\n" . EventCapture::timeline();
    }
}
