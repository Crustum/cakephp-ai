<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Http;

use Crustum\Ai\TestSuite\Http\RecordedHttp;

/**
 * Backwards-compatible recorded HTTP request.
 */
class AiHttpRequest extends RecordedHttp
{
    /**
     * Convert the public request value to the legacy test-support type.
     *
     * @param \Crustum\Ai\TestSuite\Http\RecordedHttp $request Recorded request
     * @return self
     */
    public static function fromRecorded(RecordedHttp $request): self
    {
        return new self(
            $request->method,
            $request->url,
            $request->body,
            $request->attachments,
            $request->headers,
            $request->rawBody,
        );
    }
}
