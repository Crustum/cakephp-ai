<?php
declare(strict_types=1);

namespace Crustum\Ai\Http\Contract;

/**
 * Transport-agnostic HTTP client used by AI gateways.
 *
 * The signature mirrors Cake\Http\Client
 */
interface HttpClientAdapterInterface
{
    /**
     * Send a GET request.
     *
     * @param string $url Request URL
     * @param array<string, mixed> $query Query parameters
     * @param array<string, mixed> $options Request options: headers, stream
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    public function get(string $url, array $query = [], array $options = []): HttpResponseInterface;

    /**
     * Send a POST request.
     *
     * @param string $url Request URL
     * @param string $body Request body (JSON or multipart string)
     * @param array<string, mixed> $options Request options: headers, stream
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    public function post(string $url, string $body, array $options = []): HttpResponseInterface;

    /**
     * Send a DELETE request.
     *
     * @param string $url Request URL
     * @param array<string, mixed> $query Query parameters
     * @param array<string, mixed> $options Request options: headers, stream
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    public function delete(string $url, array $query = [], array $options = []): HttpResponseInterface;
}
