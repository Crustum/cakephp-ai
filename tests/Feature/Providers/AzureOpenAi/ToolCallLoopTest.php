<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.azure', [

        ...(array)Configure::read('Ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]);
});

test('tool calls trigger follow up request', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpSequence([
            fakeUniqueAzureToolCallResponse(),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'azure',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id');

    $hasFunctionCallOutput = false;

    foreach ($followUpBody['input'] as $item) {
        if (($item['type'] ?? '') === 'function_call_output') {
            $hasFunctionCallOutput = true;
        }
    }

    expect($hasFunctionCallOutput)->toBeTrue();
});

test('max steps limits tool call depth', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpSequence([
            fakeUniqueAzureToolCallResponse(),
            fakeUniqueAzureToolCallResponse(),
            fakeUniqueAzureToolCallResponse(),
            fakeAzureResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'azure',
    );

    $recorded = aiHttpRecorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('multi step tool loop returns accumulated response shape', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpSequence([
            fakeUniqueAzureToolCallResponse(),
            fakeUniqueAzureToolCallResponse(),
            fakeAzureResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent())->prompt(
        'Generate numbers',
        provider: 'azure',
    );

    expect((string)$response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->promptTokens)->toBe(21)
        ->and($response->usage->completionTokens)->toBe(11);
});

function fakeUniqueAzureToolCallResponse(): AiHttpResponseDefinition
{
    $id = uniqid();

    return aiHttpResponse([
        'id' => 'resp_azure_tool_' . $id,
        'status' => 'completed',
        'model' => 'gpt-4o',
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
