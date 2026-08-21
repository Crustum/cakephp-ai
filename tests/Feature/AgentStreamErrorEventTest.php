<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Http\Stream\VercelProtocolStreamResponse;
use Crustum\Ai\Providers\GroqProvider;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

/**
 * Capture the SSE body emitted by a stream response without sending headers.
 *
 * @param object $response The stream response (default or Vercel)
 * @return string
 */
function emitStreamResponse(object $response): string
{
    $method = new ReflectionMethod($response, 'streamData');

    ob_start();

    try {
        $method->invoke($response);
    } finally {
        $output = (string)ob_get_clean();
    }

    return $output;
}

test('stream surfaces rate limited error as an error event instead of crashing (default SSE)', function (): void {
    Configure::write('Ai.providers.primary', [
        'className' => GroqProvider::class,
        'driver' => 'groq',
        'key' => 'test-key',
        'name' => 'primary',
    ]);

    $this->fakeProviderHttp([
        '*' => $this->httpResponse(['error' => ['message' => 'Rate limited']], 429),
    ]);

    $response = (new AssistantAgent())->stream('Hello', provider: 'primary');

    expect($response)->toBeInstanceOf(StreamableAgentResponse::class);

    $output = emitStreamResponse($response->toResponse());

    expect($output)
        ->toContain('"type":"rate_limited"')
        ->toContain('rate limited')
        ->toContain('[DONE]')
        ->not->toContain('text_delta');
});

test('stream surfaces last provider error after failover exhaustion as an error event (default SSE)', function (): void {
    Configure::write('Ai.providers.primary', [
        'className' => GroqProvider::class,
        'driver' => 'groq',
        'key' => 'test-key',
        'name' => 'primary',
    ]);
    Configure::write('Ai.providers.backup', [
        'className' => GroqProvider::class,
        'driver' => 'groq',
        'key' => 'test-key',
        'name' => 'backup',
    ]);

    $this->fakeProviderHttp([
        '*' => $this->httpSequence([
            $this->httpResponse(['error' => ['message' => 'Rate limited']], 429),
            $this->httpResponse(['error' => ['message' => 'Rate limited']], 429),
        ]),
    ]);

    $response = (new AssistantAgent())->stream('Hello', provider: ['primary', 'backup']);

    $output = emitStreamResponse($response->toResponse());

    expect($output)
        ->toContain('"type":"rate_limited"')
        ->toContain('[DONE]')
        ->not->toContain('text_delta');

    $this->assertProviderFailedOver('primary');
});

test('stream surfaces rate limited error as an error event for the Vercel protocol', function (): void {
    Configure::write('Ai.providers.primary', [
        'className' => GroqProvider::class,
        'driver' => 'groq',
        'key' => 'test-key',
        'name' => 'primary',
    ]);

    $this->fakeProviderHttp([
        '*' => $this->httpResponse(['error' => ['message' => 'Rate limited']], 429),
    ]);

    $response = (new AssistantAgent())
        ->stream('Hello', provider: 'primary')
        ->usingVercelDataProtocol();

    expect($response->toResponse())->toBeInstanceOf(VercelProtocolStreamResponse::class);

    $output = emitStreamResponse($response->toResponse());

    expect($output)
        ->toContain('"type":"error"')
        ->toContain('"errorCode":"rate_limited"')
        ->toContain('rate limited')
        ->toContain('[DONE]');
});
