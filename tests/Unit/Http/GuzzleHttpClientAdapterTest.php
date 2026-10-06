<?php
declare(strict_types=1);

use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Client\ClientEvent;
use Cake\Http\Client\Response as CakeHttpResponse;
use Crustum\Ai\Http\GuzzleHttpClientAdapter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzlePsr7Response;

/**
 * Build an adapter backed by a mock handler queue.
 *
 * @param array<int, mixed> $responses Mock handler responses
 */
function mockAdapter(array $responses): GuzzleHttpClientAdapter
{
    return new GuzzleHttpClientAdapter(30, [], HandlerStack::create(new MockHandler($responses)));
}

/**
 * Attach a recording listener for an HttpClient event.
 *
 * @param string $name Event name
 * @param array<int, \Cake\Event\EventInterface<object>> $out Recording array
 * @return array{0: string, 1: \Closure} Listener reference for removal
 */
function recordEvent(string $name, array &$out): array
{
    $listener = function (EventInterface $event) use (&$out): void {
        $out[] = $event;
    };

    EventManager::instance()->on($name, $listener);

    return [$name, $listener];
}

/**
 * Remove a listener created by recordEvent().
 *
 * @param array{0: string, 1: \Closure} $listener Listener reference
 * @return void
 */
function removeEvent(array $listener): void
{
    EventManager::instance()->off($listener[0], $listener[1]);
}

test('a non-streaming request records the full body via HttpClient.afterSend', function (): void {
    $afterSend = [];
    $afterSendStream = [];
    $a = recordEvent('HttpClient.afterSend', $afterSend);
    $b = recordEvent('HttpClient.afterSendStream', $afterSendStream);

    try {
        $adapter = mockAdapter([
            new GuzzlePsr7Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
        ]);

        $result = $adapter->post('https://api.example.com/v1/chat', '{}');
        expect($result->getStringBody())->toBe('{"ok":true}');
    } finally {
        removeEvent($a);
        removeEvent($b);
    }

    expect($afterSend)->toHaveCount(1)
        ->and($afterSend[0])->toBeInstanceOf(ClientEvent::class)
        ->and($afterSend[0]->getResult())->toBeInstanceOf(CakeHttpResponse::class)
        ->and((string)$afterSend[0]->getResult()->getBody())->toBe('{"ok":true}')
        ->and($afterSendStream)->toBe([]);
});

test('a streaming request keeps afterSend body empty but leaves the stream intact', function (): void {
    $afterSend = [];
    $afterSendStream = [];
    $a = recordEvent('HttpClient.afterSend', $afterSend);
    $b = recordEvent('HttpClient.afterSendStream', $afterSendStream);

    try {
        $adapter = mockAdapter([
            new GuzzlePsr7Response(200, ['Content-Type' => 'text/event-stream'], 'data: one'),
        ]);

        $result = $adapter->post('https://api.example.com/v1/chat', '{}', ['stream' => true]);

        // The live stream is fully readable through the tee.
        expect($result->getBody()->getContents())->toBe('data: one');
    } finally {
        removeEvent($a);
        removeEvent($b);
    }

    // The afterSend event carries an empty body so it never consumed the stream.
    expect($afterSend)->toHaveCount(1)
        ->and((string)$afterSend[0]->getResult()->getBody())->toBe('');
});

test('reading a streamed body dispatches HttpClient.afterSendStream with the full content', function (): void {
    $afterSendStream = [];
    $listener = recordEvent('HttpClient.afterSendStream', $afterSendStream);

    try {
        $adapter = mockAdapter([
            new GuzzlePsr7Response(
                200,
                ['Content-Type' => 'text/event-stream'],
                "data: one\n\ndata: two\n\n",
            ),
        ]);

        $result = $adapter->post('https://api.example.com/v1/chat', '{}', ['stream' => true]);

        $body = '';
        while (!$result->getBody()->eof()) {
            $body .= $result->getBody()->read(4);
        }

        expect($body)->toBe("data: one\n\ndata: two\n\n");
    } finally {
        removeEvent($listener);
    }

    expect($afterSendStream)->toHaveCount(1);

    /** @var \Cake\Http\Client\Response $response */
    $response = $afterSendStream[0]->getResult();
    expect((string)$response->getBody())->toBe("data: one\n\ndata: two\n\n")
        ->and($response->getStatusCode())->toBe(200);

    /** @var \Psr\Http\Message\RequestInterface $request */
    $request = $afterSendStream[0]->getRequest();
    expect($request->getMethod())->toBe('POST')
        ->and((string)$request->getUri())->toBe('https://api.example.com/v1/chat');
});

test('HttpClient.afterSendStream fires exactly once even when the stream is read past EOF', function (): void {
    $afterSendStream = [];
    $listener = recordEvent('HttpClient.afterSendStream', $afterSendStream);

    try {
        $adapter = mockAdapter([
            new GuzzlePsr7Response(200, ['Content-Type' => 'text/event-stream'], 'abc'),
        ]);

        $result = $adapter->post('https://api.example.com/v1/chat', '{}', ['stream' => true]);

        $result->getBody()->read(2);
        expect($afterSendStream)->toBe([]);

        $result->getBody()->read(2);
        expect($afterSendStream)->toHaveCount(1);

        $result->getBody()->read(2);
        $result->getBody()->getContents();
    } finally {
        removeEvent($listener);
    }

    expect($afterSendStream)->toHaveCount(1);
});

test('HttpClient.afterSendStream is not dispatched without listeners', function (): void {
    $adapter = mockAdapter([
        new GuzzlePsr7Response(200, ['Content-Type' => 'text/event-stream'], 'abc'),
    ]);

    $result = $adapter->post('https://api.example.com/v1/chat', '{}', ['stream' => true]);
    $body = '';
    while (!$result->getBody()->eof()) {
        $body .= $result->getBody()->read(2);
    }

    expect($body)->toBe('abc');
});

test('a streaming request works through the byte-at-a-time SSE parser pattern', function (): void {
    $afterSendStream = [];
    $listener = recordEvent('HttpClient.afterSendStream', $afterSendStream);

    try {
        $adapter = mockAdapter([
            new GuzzlePsr7Response(200, ['Content-Type' => 'text/event-stream'], 'SSE payload'),
        ]);

        $result = $adapter->post('https://api.example.com/v1/chat', '{}', ['stream' => true]);

        $body = '';
        while (!$result->getBody()->eof()) {
            $body .= $result->getBody()->read(1);
        }

        expect($body)->toBe('SSE payload');
    } finally {
        removeEvent($listener);
    }

    expect($afterSendStream)->toHaveCount(1)
        ->and((string)$afterSendStream[0]->getResult()->getBody())->toBe('SSE payload');
});

test('the streamed response body is not seekable, keeping consumers honest', function (): void {
    $adapter = mockAdapter([
        new GuzzlePsr7Response(200, ['Content-Type' => 'text/event-stream'], 'abc'),
    ]);

    $result = $adapter->post('https://api.example.com/v1/chat', '{}', ['stream' => true]);

    expect($result->getBody()->isSeekable())->toBeFalse()
        ->and($result->getBody()->isReadable())->toBeTrue();
});
