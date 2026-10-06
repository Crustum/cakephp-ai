<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\NoSuchToolException;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.deepseek', [

        ...(array)Configure::read('Ai.providers.deepseek'),
        'key' => 'test-key',
    ]);
});

test('tool calls trigger follow up request', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpSequence([
            fakeUniqueDeepSeekToolCallResponse(),
            fakeDeepSeekResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'deepseek',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpBody['messages'] as $message) {
        if ($message['role'] === 'assistant' && isset($message['tool_calls'])) {
            $hasAssistantWithToolCalls = true;
        }

        if ($message['role'] === 'tool') {
            $hasToolResult = true;
        }
    }

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();
});

test('max steps limits tool call depth', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpSequence([
            fakeUniqueDeepSeekToolCallResponse(),
            fakeUniqueDeepSeekToolCallResponse(),
            fakeUniqueDeepSeekToolCallResponse(),
            fakeDeepSeekResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'deepseek',
    );

    $recorded = aiHttpRecorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('multi step tool loop returns accumulated response shape', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpSequence([
            fakeUniqueDeepSeekToolCallResponse(),
            fakeUniqueDeepSeekToolCallResponse(),
            fakeDeepSeekResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent())->prompt(
        'Generate numbers',
        provider: 'deepseek',
    );

    expect((string)$response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->inputTokens)->toBe(21)
        ->and($response->usage->outputTokens)->toBe(11);
});

test('unknown tool call throws no such tool exception', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse([
            'id' => 'chatcmpl-tool-unknown',
            'object' => 'chat.completion',
            'model' => 'deepseek-chat',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_unknown',
                        'type' => 'function',
                        'function' => [
                            'name' => 'UnregisteredTool',
                            'arguments' => '{}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]),
    ]);

    expect(fn(): AgentResponse => (new MultiStepToolAgent())->prompt(
        'Generate numbers',
        provider: 'deepseek',
    ))->toThrow(NoSuchToolException::class);
});

function fakeUniqueDeepSeekToolCallResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'chatcmpl-tool-' . uniqid(),
        'object' => 'chat.completion',
        'model' => 'deepseek-chat',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_' . uniqid(),
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}
