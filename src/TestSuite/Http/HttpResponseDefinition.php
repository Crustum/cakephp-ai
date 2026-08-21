<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Http;

use Crustum\Ai\Http\Response;

/**
 * HTTP response definition for faked provider requests.
 */
class HttpResponseDefinition
{
    /**
     * @param array<string, mixed>|string $body Response body
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     */
    public function __construct(
        public array|string $body,
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    /**
     * Build an AI HTTP response.
     *
     * @return \Crustum\Ai\Http\Response
     */
    public function toResponse(): Response
    {
        $headers = $this->headers;

        if ($headers === []) {
            $headers['Content-Type'] = is_string($this->body) && !str_starts_with(trim($this->body), '{')
                ? 'text/plain'
                : 'application/json';
        }

        $content = is_array($this->body)
            ? (json_encode($this->body) ?: '{}')
            : $this->body;

        return new Response($this->status, $headers, $content);
    }
}
