<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Tool;

use Crustum\Ai\TestSuite\Capture\EventCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts a tool was invoked, optionally matching a truth test.
 *
 * @internal
 */
class ToolWasInvoked extends Constraint
{
    /**
     * @param string $name Tool name
     * @param callable|null $callback Optional truth test on RecordedToolInvocation
     */
    public function __construct(
        protected string $name,
        protected $callback = null,
    ) {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        foreach (EventCapture::tools() as $tool) {
            if ($tool->name !== $this->name) {
                continue;
            }

            if ($this->callback === null || ($this->callback)($tool)) {
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
        return sprintf('tool [%s] was invoked', $this->name);
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
