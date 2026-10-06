<?php
declare(strict_types=1);

namespace Crustum\Ai\Http;

use Cake\Event\EventManager;
use Cake\Http\Client as CakeHttpClient;
use Cake\Http\Client\ClientEvent;
use Cake\Http\Client\Response as CakeHttpResponse;
use Crustum\Ai\Http\Contract\HttpClientAdapterInterface;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\Stream\TeeStream;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle-backed implementation of the AI provider HTTP client.
 *
 * Replaces `Cake\Http\Client`, which buffers the entire response body and
 * cannot stream. With `stream => true` in the request options the response
 * body is a live stream, enabling incremental SSE parsing.
 */
class GuzzleHttpClientAdapter implements HttpClientAdapterInterface
{
    /**
     * @param int $timeout Request timeout in seconds
     * @param array<string, mixed> $config Extra Guzzle configuration
     * @param \GuzzleHttp\HandlerStack|null $handler Optional handler (tests inject a mock)
     */
    public function __construct(
        protected int $timeout = 60,
        protected array $config = [],
        protected ?HandlerStack $handler = null,
    ) {
    }

    /**
     * @param string $url Request URL
     * @param array<string, mixed> $query Query parameters
     * @param array<string, mixed> $options Request options
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    public function get(string $url, array $query = [], array $options = []): HttpResponseInterface
    {
        $options = $this->mergeQuery($options, $query);

        return $this->request('GET', $url, $options);
    }

    /**
     * @param string $url Request URL
     * @param string $body Request body (JSON or multipart string)
     * @param array<string, mixed> $options Request options
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    public function post(string $url, string $body, array $options = []): HttpResponseInterface
    {
        $options['body'] = $body;

        return $this->request('POST', $url, $options);
    }

    /**
     * @param string $url Request URL
     * @param array<string, mixed> $query Query parameters
     * @param array<string, mixed> $options Request options
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    public function delete(string $url, array $query = [], array $options = []): HttpResponseInterface
    {
        $options = $this->mergeQuery($options, $query);

        return $this->request('DELETE', $url, $options);
    }

    /**
     * Build and send a request, returning an AI response.
     *
     * Re-dispatches Cake's `HttpClient.beforeSend`/`HttpClient.afterSend`
     * events so monitoring tools keep working after the transport swap.
     *
     * @param string $method HTTP method (GET, POST, DELETE, ...)
     * @param string $url Request URL
     * @param array<string, mixed> $options Guzzle request options
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function request(string $method, string $url, array $options): HttpResponseInterface
    {
        $client = $this->client();
        $psrRequest = new Psr7Request(
            $method,
            $url,
            $options['headers'] ?? [],
            $options['body'] ?? null,
        );

        $isStreaming = !empty($options['stream']);

        $this->dispatchEvent('HttpClient.beforeSend', $psrRequest, null, $isStreaming);

        $response = $client->request($method, $url, $options);

        if ($response instanceof HttpResponseInterface) {
            $result = $response;
        } else {
            $result = new Response(
                $response->getStatusCode(),
                $response->getHeaders(),
                $response->getBody(),
                $response->getProtocolVersion(),
                $response->getReasonPhrase(),
            );
        }

        if ($isStreaming) {
            $result = $this->teeStreamingBody($psrRequest, $result);
        }

        $this->dispatchEvent('HttpClient.afterSend', $psrRequest, $result, $isStreaming);

        return $result;
    }

    /**
     * Wrap a streaming response body in a tee that captures it for logging.
     *
     * The tee is transparent: consumers read from it as they would from the
     * original stream, while every chunk is captured as a side-effect and
     * dispatched with the `HttpClient.afterSendStream` event once the stream
     * reaches EOF. Streaming is never interrupted.
     *
     * @param \Psr\Http\Message\RequestInterface $request PSR-7 request
     * @param \Crustum\Ai\Http\Contract\HttpResponseInterface $response AI response
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function teeStreamingBody(RequestInterface $request, HttpResponseInterface $response): HttpResponseInterface
    {
        $body = $response->getBody();

        if (!$body->isReadable()) {
            return $response;
        }

        $tee = new TeeStream($body);
        $tee->onComplete(function (string $captured) use ($request, $response): void {
            $this->dispatchCapturedStream($request, $response, $captured);
        });

        return $response->withBody($tee);
    }

    /**
     * Dispatch the `HttpClient.afterSendStream` event with the captured body.
     *
     * Fired by the tee stream once a streamed response body reaches EOF, so
     * monitoring tools can record the full response without consuming the
     * live stream.
     *
     * @param \Psr\Http\Message\RequestInterface $request PSR-7 request
     * @param \Crustum\Ai\Http\Contract\HttpResponseInterface $response AI response
     * @param string $body Captured response body
     * @return void
     */
    protected function dispatchCapturedStream(RequestInterface $request, HttpResponseInterface $response, string $body): void
    {
        $manager = EventManager::instance();

        if ($manager->prioritisedListeners('HttpClient.afterSendStream') === []) {
            return;
        }

        $cakeResponse = new CakeHttpResponse($this->formatHeaders($response), $body);

        $event = new ClientEvent('HttpClient.afterSendStream', new CakeHttpClient(), [
            'request' => $request,
            'response' => $cakeResponse,
        ]);

        $manager->dispatch($event);
    }

    /**
     * Dispatch a Cake HttpClient event for monitoring compatibility.
     *
     * The response body is omitted for streaming requests so the live stream
     * is never consumed by monitoring.
     *
     * @param string $name Event name
     * @param \Psr\Http\Message\RequestInterface $request PSR-7 request
     * @param \Crustum\Ai\Http\Contract\HttpResponseInterface|null $response AI response
     * @param bool $isStreaming Whether the request was a streaming request
     * @return void
     */
    protected function dispatchEvent(string $name, RequestInterface $request, ?HttpResponseInterface $response, bool $isStreaming = false): void
    {
        $manager = EventManager::instance();

        if ($manager->prioritisedListeners($name) === []) {
            return;
        }

        $subject = new CakeHttpClient();
        $cakeResponse = null;

        if ($response instanceof HttpResponseInterface) {
            $isStreamResponse = $isStreaming
                || $response->getHeaderLine('Content-Type') === 'text/event-stream';

            $cakeResponse = new CakeHttpResponse(
                $this->formatHeaders($response),
                $isStreamResponse ? '' : (string)$response->getBody(),
            );
        }

        $event = new ClientEvent($name, $subject, [
            'request' => $request,
            'response' => $cakeResponse,
            'is_streaming' => $isStreaming,
        ]);

        $manager->dispatch($event);
    }

    /**
     * Format a Cake-compatible header list: status line plus `Name: value`.
     *
     * Cake's `Client\Response` derives the status code only from a leading
     * `HTTP/x.y ZZZ` status line, so it must be included for monitoring to
     * record the real response status.
     *
     * @param \Crustum\Ai\Http\Contract\HttpResponseInterface $response AI response
     * @return array<int, string>
     */
    protected function formatHeaders(HttpResponseInterface $response): array
    {
        $statusLine = sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        );

        $headers = [$statusLine];
        foreach ($response->getHeaders() as $headerName => $values) {
            $headers[] = $headerName . ': ' . implode(', ', $values);
        }

        return $headers;
    }

    /**
     * Build the Guzzle client.
     *
     * @return \GuzzleHttp\Client
     */
    protected function client(): Client
    {
        $config = array_merge([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
            'http_errors' => false,
        ], $this->config);

        if ($this->handler instanceof HandlerStack) {
            $config['handler'] = $this->handler;
        }

        return new Client($config);
    }

    /**
     * Merge a query array into the request options.
     *
     * @param array<string, mixed> $options Request options
     * @param array<string, mixed> $query Query parameters
     * @return array<string, mixed>
     */
    protected function mergeQuery(array $options, array $query): array
    {
        if ($query === []) {
            return $options;
        }

        $options['query'] = $query;

        return $options;
    }
}
