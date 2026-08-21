<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.azure', [

        ...(array)Configure::read('Ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]);
});

test('provider options are included in azure request body', function (): void {
    aiHttpFake([
        '*' => fakeAzureResponse('Hello'),
    ]);

    (new ProviderOptionsAgent())->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'frequency_penalty') === 0.5
            && Hash::get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    aiHttpFake([
        '*' => fakeAzureResponse('Hello'),
    ]);

    agent()->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('reasoning', $body)
            && ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            fakeAzureProviderOptionsToolCallResponse(),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent())->prompt('Give me a number', provider: 'azure');

    $requests = aiHttpRecorded(fn(AiHttpRequest $r): true => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode((string)$requests[1][0]->body(), true);

    expect(Hash::get($followUpBody, 'frequency_penalty'))->toBe(0.5);
});

function fakeAzureProviderOptionsToolCallResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'resp_azure_tool_123',
        'status' => 'completed',
        'model' => 'gpt-4o',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_123',
            'call_id' => 'call_123',
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
