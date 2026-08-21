<?php
declare(strict_types=1);

use Cake\Event\EventManager;
use Cake\Utility\Hash;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\AgentStreamed;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\StructuredAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;

test('text responses expose the raw http response', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => $this->fakeTextResponse('Hello there'),
    ]);

    $response = (new AssistantAgent())->prompt(
        'Hi',
        provider: 'anthropic',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'content.0.text'))->toBe('Hello there');
});

test('structured responses expose the raw http response', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
    ]);

    $response = (new StructuredAgent())->prompt(
        'Tell me about Taylor',
        provider: 'anthropic',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'id'))->toBe('msg_123');
});

test('tool call loops expose the raw http response of the final step', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'content.0.text'))->toBe('The number is 72019');
});

test('each step exposes the raw http response of its own request', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    $steps = $response->steps->toList();

    expect($steps)->toHaveCount(2)
        ->and($steps[0]->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($steps[0]->raw->getJson() ?? [], 'stop_reason'))->toBe('tool_use')
        ->and($steps[1]->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($steps[1]->raw->getJson() ?? [], 'content.0.text'))->toBe('The number is 72019');
});

test('agent prompted event exposes the raw http response', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => $this->fakeTextResponse('Hello there'),
    ]);

    $raw = null;

    EventManager::instance()->on(AgentPrompted::eventName(), function (AgentPrompted $event) use (&$raw): void {
        $raw = $event->response->raw;
    });

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'anthropic',
    );

    expect($raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($raw->getJson() ?? [], 'content.0.text'))->toBe('Hello there');
});

test('streamed responses have a null raw http response', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->messageStart(),
                $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                $this->contentBlockStop(0),
                $this->messageDelta('end_turn', 10),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $invoked = false;

    EventManager::instance()->on(AgentStreamed::eventName(), function (AgentStreamed $event) use (&$invoked): void {
        $invoked = true;

        expect($event->response->raw)->toBeNull();
    });

    $this->collectStreamEvents();

    expect($invoked)->toBeTrue();
});

test('responses discard the raw http response when serialized', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    $restored = unserialize(serialize($response));

    $restored->steps->rewind();

    $steps = $restored->steps->toList();

    expect($restored->raw)->toBeNull()
        ->and($restored->text)->toBe('The number is 72019')
        ->and($steps)->toHaveCount(2)
        ->and($steps[1]->raw)->toBeNull()
        ->and($steps[1]->text)->toBe('The number is 72019');
});
