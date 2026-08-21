<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Http;

use Closure;
use Crustum\Ai\TestSuite\Capture\HttpCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts the number of recorded provider HTTP requests.
 *
 * @internal
 */
class HttpRequestCount extends Constraint
{
    protected ?Closure $filter;

    /**
     * @param callable|null $filter Optional request filter
     */
    public function __construct(?callable $filter = null)
    {
        $this->filter = $filter === null ? null : Closure::fromCallable($filter);
    }

    /**
     * @param mixed $other Expected count
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        return count($this->filteredRequests()) === (int)$other;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return 'provider HTTP request count matches expected value';
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        $actual = count($this->filteredRequests());

        return sprintf(
            'provider HTTP request count is %d matching expected %d',
            $actual,
            (int)$other,
        ) . "\n" . HttpCapture::timeline();
    }

    /**
     * @return array<int, \Crustum\Ai\TestSuite\Http\RecordedHttp>
     */
    protected function filteredRequests(): array
    {
        $requests = HttpCapture::recordedRequests();

        if (!$this->filter instanceof Closure) {
            return $requests;
        }

        return array_values(array_filter($requests, $this->filter));
    }
}
