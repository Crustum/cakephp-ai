<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\StoppedException;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('an immediate stop aborts the stream before any provider call', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDone('Hello'),
                $this->responseCompleted(10, 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $response = (new AssistantAgent())->stream('Hello', provider: 'openai', stopCheck: fn(): bool => true);

    $events = [];

    try {
        foreach ($response as $event) {
            $events[] = $event;
        }

        expect(true)->toBeFalse('The stream must throw a StoppedException.');
    } catch (StoppedException) {
    }

    expect($response->wasStopped())->toBeTrue()
        ->and($response->hasYielded())->toBeFalse()
        ->and($events)->toBe([]);
});

test('a quiet stop predicate streams to completion without a stopped flag', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDone('Hello'),
                $this->responseCompleted(10, 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $response = (new AssistantAgent())->stream('Hello', provider: 'openai', stopCheck: fn(): bool => false);

    $events = iterator_to_array($response);

    expect($events)->not->toBe([])
        ->and($response->wasStopped())->toBeFalse()
        ->and($response->text)->toBe('Hello');
});
