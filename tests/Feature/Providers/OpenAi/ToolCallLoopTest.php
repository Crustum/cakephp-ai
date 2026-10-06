<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolChoiceAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\TestSuite\Http\RecordedHttp;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('tool calls trigger follow up request', function (): void {
    $this->fakeProviderHttp([
        'api.openai.com/*' => $this->httpSequence([
            fakeUniqueOpenAiToolCallResponse(),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'openai',
    );

    $this->assertHttpSentCount(2);
    $this->assertHttpSentInOrder([
        fn(RecordedHttp $request): bool => $request->json('previous_response_id') === null,
        fn(RecordedHttp $request): bool => $request->json('previous_response_id') !== null
            && $request->containsToolOutput('FixedNumberGenerator'),
    ]);
    $this->assertToolInvoked('FixedNumberGenerator');
    $this->assertToolResultContains('FixedNumberGenerator', '72019');
    $this->assertStepsContainTool('FixedNumberGenerator');
});

test('max steps limits tool call depth', function (): void {
    $this->fakeProviderHttp([
        'api.openai.com/*' => $this->httpSequence([
            fakeUniqueOpenAiToolCallResponse(),
            fakeUniqueOpenAiToolCallResponse(),
            fakeUniqueOpenAiToolCallResponse(),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'openai',
    );

    $this->assertMaxStepsHonored(3);
});

test('multi step tool loop returns accumulated response shape', function (): void {
    $this->fakeProviderHttp([
        'api.openai.com/*' => $this->httpSequence([
            fakeUniqueOpenAiToolCallResponse(),
            fakeUniqueOpenAiToolCallResponse(),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent())->prompt(
        'Generate numbers',
        provider: 'openai',
    );

    expect((string)$response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->inputTokens)->toBe(21)
        ->and($response->usage->outputTokens)->toBe(11);

    $this->assertStepCount(3);
    $this->assertToolInvokedTimes('FixedNumberGenerator', 2);
    $this->assertToolsInvokedInOrder(['FixedNumberGenerator', 'FixedNumberGenerator']);
    $this->assertUsageAccumulated();
});

test('a forced tool choice is released on the follow up request', function (): void {
    $this->fakeProviderHttp([
        'api.openai.com/*' => $this->httpSequence([
            fakeOpenAiRandomNumberToolCallResponse(),
            fakeOpenAiResponse('The number is 7'),
        ]),
    ]);

    (new ToolChoiceAgent('required'))->prompt('Generate a random number', provider: 'openai');

    $this->assertHttpSentCount(2);
    $this->assertHttpSentInOrder([
        fn(RecordedHttp $request): bool => $request->json('tool_choice') === 'required',
        fn(RecordedHttp $request): bool => $request->json('tool_choice') === 'auto',
    ]);
    $this->assertToolInvoked('RandomNumberGenerator');
});
function fakeOpenAiRandomNumberToolCallResponse(): AiHttpResponseDefinition
{
    $id = uniqid();

    return aiHttpResponse([
        'id' => 'resp_tool_' . $id,
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_' . $id,
            'call_id' => 'call_' . $id,
            'name' => 'RandomNumberGenerator',
            'arguments' => '{"min":1,"max":10}',
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}

function fakeUniqueOpenAiToolCallResponse(): AiHttpResponseDefinition
{
    $id = uniqid();

    return aiHttpResponse([
        'id' => 'resp_tool_' . $id,
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_' . $id,
            'call_id' => 'call_' . $id,
            'name' => 'FixedNumberGenerator',
            'arguments' => '{}',
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}
