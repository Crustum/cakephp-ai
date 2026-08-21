<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Tool;

use Crustum\Ai\TestSuite\Capture\EventCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts a tool was not invoked.
 *
 * @internal
 */
class ToolWasNotInvoked extends Constraint
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
        foreach (EventCapture::tools() as $tool) {
            if ($tool->name === $this->name) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('tool [%s] was not invoked', $this->name);
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
