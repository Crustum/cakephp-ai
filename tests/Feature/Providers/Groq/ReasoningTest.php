<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Trait\PromptableTrait;

beforeEach(function (): void {
    Configure::write('Ai.providers.groq', [

        ...(array)Configure::read('Ai.providers.groq'),
        'key' => 'test-key',
    ]);
});

test('prompt reads the parsed reasoning off the response', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => 'Hello',
                'reasoning' => 'Let me think...',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ])]);

    $response = (new AssistantAgent())->prompt('Hi', provider: 'groq');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
});

// Groq rejects reasoning_format on non-reasoning and GPT-OSS models, so the caller opts in per model...
test('no reasoning format is sent unless the caller asks for one', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('Hello')]);

    (new AssistantAgent())->prompt('Hi', provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => ! array_key_exists('reasoning_format', $request->data()));
});

test('the reasoning format can be set with provider options', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('Hello')]);

    $agent = new class implements Agent, HasProviderOptions
    {
        use PromptableTrait;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['reasoning_format' => 'hidden'];
        }
    };

    $agent->prompt('Hi', provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->data()['reasoning_format'] === 'hidden');
});

test('streaming emits reasoning events before the text', function (): void {
    aiHttpFake(['*' => aiHttpResponse(
        body: $this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Let me ']),
            $this->chatChunk(['reasoning' => 'think...']),
            $this->chatChunk(['content' => 'Hello']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
        ]),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...');
});
