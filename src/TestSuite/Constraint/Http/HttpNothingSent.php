<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Http;

use Crustum\Ai\TestSuite\Capture\HttpCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts that no provider HTTP requests were sent.
 *
 * @internal
 */
class HttpNothingSent extends Constraint
{
    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        return HttpCapture::recordedRequests() === [];
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return 'no provider HTTP requests were sent';
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
