<?php
declare(strict_types=1);

namespace Crustum\Ai\Http;

use Crustum\Ai\Http\Contract\HttpResponseInterface;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

/**
 * AI provider HTTP response.
 *
 * Wraps a Guzzle PSR-7 response and adds the convenience methods the gateways
 * already use (`getJson()`, `isOk()`). For streaming requests the body is a live
 * stream, so `getBody()` exposes `eof()`/`read()` for incremental SSE parsing.
 */
class Response extends GuzzleResponse implements HttpResponseInterface
{
    /**
     * Decode the response body as JSON.
     *
     * @return array<string, mixed>|null
     */
    public function getJson(): ?array
    {
        $decoded = json_decode((string)$this->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Determine whether the response status indicates success.
     */
    public function isOk(): bool
    {
        $status = $this->getStatusCode();

        return $status >= 200 && $status < 300;
    }

    /**
     * Get the response body as a string (Cake-compatible convenience).
     *
     * @return string
     */
    public function getStringBody(): string
    {
        return (string)$this->getBody();
    }
}
