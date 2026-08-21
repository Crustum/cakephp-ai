<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Http;

use ArrayAccess;
use Cake\Utility\Hash;
use Psr\Http\Message\RequestInterface;

/**
 * Recorded provider HTTP request for test assertions.
 */
class RecordedHttp implements ArrayAccess
{
    /**
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<string, mixed> $body Request body fields
     * @param array<int, array<string, mixed>> $attachments Multipart attachments
     * @param array<string, string> $headers Request headers
     * @param string $rawBody Raw request body
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $body,
        public array $attachments,
        public array $headers,
        public string $rawBody = '',
    ) {
    }

    /**
     * Get the raw request body.
     *
     * @return string
     */
    public function body(): string
    {
        if ($this->rawBody !== '') {
            return $this->rawBody;
        }

        if ($this->attachments !== []) {
            return $this->buildMultipartBody();
        }

        if ($this->body === []) {
            return '';
        }

        return json_encode($this->body) ?: '';
    }

    /**
     * Get the HTTP method.
     *
     * @return string
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Get the request URL.
     *
     * @return string
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * Decode the JSON request body.
     *
     * @param string|null $path Optional Hash path
     * @return mixed
     */
    public function json(?string $path = null): mixed
    {
        $decoded = json_decode($this->body(), true);

        if (!is_array($decoded)) {
            return null;
        }

        if ($path === null) {
            return $decoded;
        }

        return Hash::get($decoded, $path);
    }

    /**
     * Determine whether the request body contains user text.
     *
     * @param string $needle Expected text
     * @return bool
     */
    public function hasUserText(string $needle): bool
    {
        $input = $this->json('input');

        if (!is_array($input)) {
            return str_contains($this->body(), $needle);
        }

        foreach ($input as $message) {
            if (!is_array($message)) {
                continue;
            }

            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $content = $message['content'] ?? null;

            if (is_string($content) && str_contains($content, $needle)) {
                return true;
            }

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (is_array($part) && str_contains((string)($part['text'] ?? ''), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether a follow-up request includes tool output for a tool.
     *
     * @param string|null $tool Optional tool name when the provider includes it
     * @return bool
     */
    public function containsToolOutput(?string $tool = null): bool
    {
        $input = $this->json('input');

        if (!is_array($input)) {
            return false;
        }

        foreach ($input as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? null;

            if (
                $type === 'function_call_output'
                && ($tool === null || !isset($item['name']) || $item['name'] === $tool)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the request has a header.
     *
     * @param string $name Header name
     * @param string|null $value Optional expected value
     * @return bool
     */
    public function hasHeader(string $name, ?string $value = null): bool
    {
        $normalized = $this->normalizeHeaderName($name);

        foreach ($this->headers as $headerName => $headerValue) {
            if ($this->normalizeHeaderName((string)$headerName) !== $normalized) {
                continue;
            }

            if ($value === null) {
                return true;
            }

            return $headerValue === $value;
        }

        return false;
    }

    /**
     * Get header values by name.
     *
     * @param string $name Header name
     * @return array<int, string>
     */
    public function header(string $name): array
    {
        $normalized = $this->normalizeHeaderName($name);
        $values = [];

        foreach ($this->headers as $headerName => $headerValue) {
            if ($this->normalizeHeaderName((string)$headerName) === $normalized) {
                $values[] = (string)$headerValue;
            }
        }

        return $values;
    }

    /**
     * Get decoded request data.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->body;
    }

    /**
     * Determine if the request was sent as multipart form data.
     *
     * @return bool
     */
    public function isMultipart(): bool
    {
        return $this->attachments !== [];
    }

    /**
     * Summarize the request for assertion failure output.
     *
     * @return string
     */
    public function summary(): string
    {
        $model = $this->json('model');
        $parts = [
            $this->method,
            $this->url,
        ];

        if (is_string($model) && $model !== '') {
            $parts[] = 'model=' . $model;
        }

        $tools = $this->json('tools');
        if (is_array($tools)) {
            $parts[] = 'tools=' . count($tools);
        }

        if ($this->json('previous_response_id') !== null) {
            $parts[] = 'previous_response_id';
        }

        if ($this->containsToolOutput()) {
            $parts[] = 'tool_outputs';
        }

        return implode(' ', $parts);
    }

    /**
     * @param mixed $offset Offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->body);
    }

    /**
     * @param mixed $offset Offset
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->body[$offset];
    }

    /**
     * @param mixed $offset Offset
     * @param mixed $value Value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->body[$offset] = $value;
    }

    /**
     * @param mixed $offset Offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->body[$offset]);
    }

    /**
     * Build a multipart body representation for assertions.
     *
     * @return string
     */
    protected function buildMultipartBody(): string
    {
        $parts = [];

        foreach ($this->body as $name => $value) {
            $parts[] = $name . '=' . (is_scalar($value) ? (string)$value : (json_encode($value) ?: ''));
        }

        foreach ($this->attachments as $attachment) {
            $parts[] = ($attachment['field'] ?? 'file') . '=' . ($attachment['filename'] ?? 'file');
        }

        return implode('&', $parts);
    }

    /**
     * Normalize a header name for comparison.
     *
     * @param string $name Header name
     * @return string
     */
    protected function normalizeHeaderName(string $name): string
    {
        return strtolower(str_replace('_', '-', $name));
    }

    /**
     * Build a recorded request from a PSR-7 request.
     *
     * @param \Psr\Http\Message\RequestInterface $request PSR request
     * @return self
     */
    public static function fromPsrRequest(RequestInterface $request): self
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = $values[0] ?? '';
        }

        $rawBody = (string)$request->getBody();
        $contentType = $headers['Content-Type'] ?? '';
        $body = [];
        $attachments = [];

        if (str_contains($contentType, 'multipart/form-data')) {
            $parsed = self::parseMultipartBody($rawBody, $contentType);
            $body = $parsed['fields'];
            $attachments = $parsed['attachments'];
        } elseif ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            $body = is_array($decoded) ? $decoded : ['_raw' => $rawBody];
        }

        return new self(
            strtoupper($request->getMethod()),
            (string)$request->getUri(),
            $body,
            $attachments,
            $headers,
            $rawBody,
        );
    }

    /**
     * Parse multipart form data from a request body.
     *
     * @param string $rawBody Raw request body
     * @param string $contentType Content-Type header
     * @return array{fields: array<string, mixed>, attachments: array<int, array<string, mixed>>}
     */
    protected static function parseMultipartBody(string $rawBody, string $contentType): array
    {
        $fields = [];
        $attachments = [];

        if (!preg_match('/boundary=([^;\s]+)/i', $contentType, $matches)) {
            return ['fields' => $fields, 'attachments' => $attachments];
        }

        $boundary = trim($matches[1], '"');
        $segments = explode('--' . $boundary, $rawBody);

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            if ($segment === '--') {
                continue;
            }

            [$rawHeaders, $content] = array_pad(preg_split("/\R\R/", $segment, 2) ?: [], 2, '');
            $fieldName = null;
            $filename = null;
            $partContentType = null;

            foreach (preg_split("/\R/", $rawHeaders) ?: [] as $headerLine) {
                if (preg_match('/^Content-Disposition:\s*form-data;\s*(.+)$/i', $headerLine, $headerMatches)) {
                    if (preg_match('/name="([^"]+)"/', $headerMatches[1], $nameMatch)) {
                        $fieldName = $nameMatch[1];
                    }

                    if (preg_match('/filename="([^"]*)"/', $headerMatches[1], $filenameMatch)) {
                        $filename = $filenameMatch[1];
                    }
                }

                if (preg_match('/^Content-Type:\s*(.+)$/i', $headerLine, $typeMatch)) {
                    $partContentType = trim($typeMatch[1]);
                }
            }

            if ($fieldName === null) {
                continue;
            }

            $content = rtrim($content, "\r\n");

            if ($filename !== null) {
                $attachments[] = [
                    'field' => $fieldName,
                    'filename' => $filename,
                    'content' => $content,
                    'headers' => array_filter(['Content-Type' => $partContentType]),
                ];

                continue;
            }

            $fields[$fieldName] = trim((string)strtok($content, "\r\n"));
        }

        return ['fields' => $fields, 'attachments' => $attachments];
    }
}
