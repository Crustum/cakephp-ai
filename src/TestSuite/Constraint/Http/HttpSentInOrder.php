<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Http;

use Crustum\Ai\TestSuite\Capture\HttpCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts provider HTTP requests matched ordered truth tests.
 *
 * @internal
 */
class HttpSentInOrder extends Constraint
{
    /**
     * @param array<int, callable> $callbacks Ordered truth tests
     */
    public function __construct(protected array $callbacks)
    {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        $requests = HttpCapture::recordedRequests();

        if (count($requests) < count($this->callbacks)) {
            return false;
        }

        foreach ($this->callbacks as $index => $callback) {
            if (!isset($requests[$index]) || !$callback($requests[$index])) {
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
        return 'provider HTTP requests were sent in the expected order';
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return $this->toString() . "\n" . HttpCapture::timeline();
    }
}
