<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Stream;

use Crustum\Ai\TestSuite\Capture\StreamCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts concatenated stream text deltas contain a needle.
 *
 * @internal
 */
class StreamTextContains extends Constraint
{
    /**
     * @param string $needle Expected text fragment
     */
    public function __construct(protected string $needle)
    {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        return str_contains(StreamCapture::combinedText(), $this->needle);
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('stream text contains [%s]', $this->needle);
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return $this->toString()
            . "\nActual: " . json_encode(StreamCapture::combinedText())
            . "\n" . StreamCapture::timeline();
    }
}
