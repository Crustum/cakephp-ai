<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Capture;

/**
 * Root capture context for agentic flow assertions.
 */
class FlowCapture
{
    /**
     * Reset all flow captures for the current test.
     *
     * @return void
     */
    public static function reset(): void
    {
        HttpCapture::reset();
        EventCapture::reset();
        StreamCapture::reset();
    }

    /**
     * Reset event/stream capture without clearing HTTP fakes.
     *
     * Used before each test so Pest/PHPUnit `beforeEach` can call `aiHttpFake()` /
     * `fakeProviderHttp()` without the trait wiping those mocks afterward.
     *
     * @return void
     */
    public static function begin(): void
    {
        EventCapture::reset();
        StreamCapture::reset();
    }
}
