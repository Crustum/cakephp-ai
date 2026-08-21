<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Http;

use Crustum\Ai\TestSuite\Capture\HttpCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts that a matching provider HTTP request was sent.
 *
 * @internal
 */
class HttpRequestSent extends Constraint
{
    /**
     * @param mixed $other Callable truth test receiving RecordedHttp
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        if (!is_callable($other)) {
            return false;
        }

        foreach (HttpCapture::recordedRequests() as $request) {
            if ($other($request)) {
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
        return 'a matching provider HTTP request was sent';
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
