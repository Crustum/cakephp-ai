<?php
declare(strict_types=1);

namespace Crustum\Ai\Http\Contract;

use Psr\Http\Message\ResponseInterface;

/**
 * AI provider HTTP response contract.
 *
 * Extends PSR-7 so the body is a StreamInterface with `eof()`/`read()`,
 * which `ParsesServerSentEventsTrait` uses to stream SSE events incrementally.
 */
interface HttpResponseInterface extends ResponseInterface
{
    /**
     * Decode the response body as JSON.
     *
     * @return array<string, mixed>|null
     */
    public function getJson(): ?array;

    /**
     * Determine whether the response status indicates success.
     */
    public function isOk(): bool;

    /**
     * Get the response body as a string.
     *
     * @return string
     */
    public function getStringBody(): string;
}
