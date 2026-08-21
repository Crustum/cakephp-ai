<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\NoSuchToolException;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.ollama.key', '');
});

test('tool calls trigger follow up request', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            fakeUniqueOllamaToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'ollama',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpMessages = collect(json_decode($recorded[1][0]->body(), true)['messages']);

    expect($followUpMessages->some(fn($m): bool => ($m['role'] ?? null) === 'assistant' && isset($m['tool_calls'])))->toBeTrue()
        ->and($followUpMessages->some(fn($m): bool => ($m['role'] ?? null) === 'tool'))->toBeTrue();
});

test('tool result message uses tool_name field', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            fakeUniqueOllamaToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'ollama',
    );

    $recorded = aiHttpRecorded();
    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $toolMsg = collect($followUpBody['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'tool')->first();

    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg)->toHaveKey('tool_name')
        ->and($toolMsg['tool_name'])->toBe('FixedNumberGenerator')
        ->and($toolMsg)->not->toHaveKey('tool_call_id');
});

test('max steps limits tool call depth', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            fakeUniqueOllamaToolCallResponse(),
            fakeUniqueOllamaToolCallResponse(),
            fakeUniqueOllamaToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'ollama',
    );

    $recorded = aiHttpRecorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('tool calls without id are executed with a generated id', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'function' => [
                            'name' => 'FixedNumberGenerator',
                            'arguments' => (object)[],
                        ],
                    ]],
                ],
                'done_reason' => 'tool_calls',
                'done' => true,
                'prompt_eval_count' => 10,
                'eval_count' => 5,
            ]),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'ollama',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $toolMsg = collect($followUpBody['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'tool')->first();

    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg['tool_name'])->toBe('FixedNumberGenerator');
});

test('tool calls are executed even when done_reason is stop', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_123',
                        'function' => [
                            'name' => 'FixedNumberGenerator',
                            'arguments' => (object)[],
                        ],
                    ]],
                ],
                'done_reason' => 'stop',
                'done' => true,
                'prompt_eval_count' => 10,
                'eval_count' => 5,
            ]),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'ollama',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2)
        ->and($response->text)->toBe('The number is 72019');
});

test('multi step tool loop returns accumulated response shape', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            fakeUniqueOllamaToolCallResponse(),
            fakeUniqueOllamaToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent())->prompt(
        'Generate numbers',
        provider: 'ollama',
    );

    expect((string)$response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->promptTokens)->toBe(21)
        ->and($response->usage->completionTokens)->toBe(11);
});

test('unregistered tool call throws NoSuchToolException', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'model' => 'llama3.1:8b',
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_123',
                    'function' => [
                        'name' => 'UnregisteredTool',
                        'arguments' => (object)[],
                    ],
                ]],
            ],
            'done_reason' => 'tool_calls',
            'done' => true,
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ]),
    ]);

    expect(fn(): AgentResponse => (new MultiStepToolAgent())->prompt(
        'Generate a number',
        provider: 'ollama',
    ))->toThrow(NoSuchToolException::class);
});

function fakeUniqueOllamaToolCallResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'model' => 'llama3.1:8b',
        'message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_' . uniqid(),
                'function' => [
                    'name' => 'FixedNumberGenerator',
                    'arguments' => (object)[],
                ],
            ]],
        ],
        'done_reason' => 'tool_calls',
        'done' => true,
        'prompt_eval_count' => 10,
        'eval_count' => 5,
    ]);
}
