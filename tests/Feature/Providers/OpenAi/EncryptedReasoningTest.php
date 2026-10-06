<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\OpenAiAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
    Configure::write('Ai.providers.openai.store', false);
});

test('initial request includes store false and reasoning encrypted content in include', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('Hello'),
    ]);

    (new OpenAiAgent())->prompt('Hello');

    aiAssertHttpSent(function ($request): bool {
        $body = json_decode((string)$request->body(), true);

        return ($body['store'] ?? null) === false
            && in_array('reasoning.encrypted_content', $body['include'] ?? [], true);
    });
});

test('tool follow up omits previous response id and echoes encrypted reasoning back inline', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpSequence([
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $recorded = aiHttpRecorded();
    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp)->not->toHaveKey('previous_response_id')
        ->and($followUp['store'] ?? null)->toBeFalse()
        ->and($followUp['include'] ?? [])->toContain('reasoning.encrypted_content');

    $input = collect($followUp['input']);

    expect($input->some(fn($i): bool => ($i['role'] ?? null) === 'user'
        && collect($i['content'] ?? [])->some(fn($c): bool => ($c['text'] ?? '') === 'Generate a number')))
        ->toBeTrue('original user message resent inline')
        ->and($input->some(fn($i): bool => ($i['type'] ?? null) === 'reasoning'
            && ($i['id'] ?? null) === 'rs_1'
            && ($i['encrypted_content'] ?? null) === 'enc-blob-1'))
        ->toBeTrue('reasoning block with encrypted_content round-tripped')
        ->and($input->some(fn($i): bool => ($i['type'] ?? null) === 'function_call'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('assistant function call resent')
        ->and($input->some(fn($i): bool => ($i['type'] ?? null) === 'function_call_output'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('tool result included');
});

test('multi step tool loop accumulates encrypted reasoning across steps', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpSequence([
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_2', 'enc-blob-2', 'fc_2', 'call_2'),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new MultiStepToolAgent())->prompt('Generate a number', provider: 'openai');

    $recorded = aiHttpRecorded();
    expect($recorded)->toHaveCount(3);

    $finalFollowUp = json_decode($recorded[2][0]->body(), true);

    expect($finalFollowUp)->not->toHaveKey('previous_response_id');

    $input = collect($finalFollowUp['input']);

    expect($input->filter(fn($i): bool => ($i['type'] ?? null) === 'reasoning'
        && ($i['encrypted_content'] ?? null) === 'enc-blob-1')->count())->toBe(1)
        ->and($input->filter(fn($i): bool => ($i['type'] ?? null) === 'reasoning'
            && ($i['encrypted_content'] ?? null) === 'enc-blob-2')->count())->toBe(1)
        ->and($input->filter(fn($i): bool => ($i['type'] ?? null) === 'function_call_output')->count())->toBe(2);
});

test('streaming tool follow up echoes encrypted reasoning back inline', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpSequence([
            aiHttpResponse(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    [
                        'type' => 'response.output_item.done',
                        'item' => [
                            'type' => 'reasoning',
                            'id' => 'rs_1',
                            'summary' => [],
                            'encrypted_content' => 'enc-blob-1',
                        ],
                    ],
                    $this->outputItemAdded('fc_1', 'call_1', 'FixedNumberGenerator'),
                    $this->functionCallArgumentsDelta('fc_1', '{}'),
                    $this->functionCallArgumentsDone('fc_1', '{}'),
                    $this->responseCompleted(10, 5, output: [
                        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
                        ['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                    ]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            aiHttpResponse(
                body: $this->ssePayload([
                    [
                        'type' => 'response.created',
                        'response' => ['id' => 'resp_2', 'model' => 'gpt-5.4', 'status' => 'in_progress', 'output' => []],
                    ],
                    $this->outputTextDelta('Done'),
                    $this->outputTextDone('Done'),
                    $this->responseCompleted(20, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = [];
    foreach ((new ProviderOptionsWithToolsAgent())->stream('Generate a number', provider: 'openai') as $event) {
        $events[] = $event;
    }

    $recorded = aiHttpRecorded();
    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp)->not->toHaveKey('previous_response_id')
        ->and($followUp['store'] ?? null)->toBeFalse()
        ->and($followUp['include'] ?? [])->toContain('reasoning.encrypted_content');

    $input = collect($followUp['input']);

    expect($input->some(fn($i): bool => ($i['type'] ?? null) === 'reasoning'
        && ($i['id'] ?? null) === 'rs_1'
        && ($i['encrypted_content'] ?? null) === 'enc-blob-1'))
        ->toBeTrue('streamed reasoning block with encrypted_content round-tripped')
        ->and($input->some(fn($i): bool => ($i['type'] ?? null) === 'function_call'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('streamed function call resent')
        ->and($input->some(fn($i): bool => ($i['type'] ?? null) === 'function_call_output'
            && ($i['call_id'] ?? null) === 'call_1'))
        ->toBeTrue('streamed tool result included');
});

test('non-reasoning model omits reasoning.encrypted_content include even with store false', function (string $model): void {
    aiHttpFake(['api.openai.com/*' => fakeOpenAiResponse()]);

    (new OpenAiAgent())->prompt('Hi', model: $model);

    aiAssertHttpSent(function ($request): bool {
        $body = json_decode((string)$request->body(), true);

        return ($body['store'] ?? null) === false
            && ! in_array('reasoning.encrypted_content', $body['include'] ?? [], true);
    });
})->with([
    'gpt-4.1',
    'gpt-4o',
    'gpt-5-chat-latest',
]);

test('store accepts env-style string values', function (mixed $storeValue, bool $shouldBeStateless): void {
    Configure::write('Ai.providers.openai.store', $storeValue);

    aiHttpFake(['api.openai.com/*' => fakeOpenAiResponse()]);

    (new OpenAiAgent())->prompt('Hi');

    aiAssertHttpSent(function ($request) use ($shouldBeStateless): bool {
        $body = json_decode((string)$request->body(), true);
        $isStateless = ($body['store'] ?? null) === false;

        return $isStateless === $shouldBeStateless;
    });
})->with([
    'bool false' => [false, true],
    'string "false"' => ['false', true],
    'string "0"' => ['0', true],
    'string "no"' => ['no', true],
    'bool true' => [true, false],
    'string "true"' => ['true', false],
    'unrecognized string' => ['maybe', false],
]);

test('default store true preserves previous response id behaviour', function (): void {
    Configure::write('Ai.providers.openai.store', true);

    aiHttpFake([
        'api.openai.com/*' => aiHttpSequence([
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $recorded = aiHttpRecorded();
    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp)->toHaveKey('previous_response_id')
        ->and($followUp['previous_response_id'])->toBe('resp_tool_1')
        ->and($followUp)->not->toHaveKey('store')
        ->and($followUp['include'] ?? [])->toContain('reasoning.encrypted_content');

    $input = collect($followUp['input']);

    expect($input)->toHaveCount(1)
        ->and($input->first()['type'] ?? null)->toBe('function_call_output')
        ->and($input->first()['call_id'] ?? null)->toBe('call_1')
        ->and($input->some(fn($i): bool => ($i['role'] ?? null) === 'user'))->toBeFalse()
        ->and($input->some(fn($i): bool => ($i['type'] ?? null) === 'reasoning'))->toBeFalse();
});

function fakeOpenAiToolCallResponseWithEncryptedReasoning(string $reasoningId, string $encryptedContent, string $functionCallId, string $callId): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'resp_tool_1',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [
            [
                'type' => 'reasoning',
                'id' => $reasoningId,
                'summary' => [],
                'encrypted_content' => $encryptedContent,
            ],
            [
                'type' => 'function_call',
                'id' => $functionCallId,
                'call_id' => $callId,
                'name' => 'FixedNumberGenerator',
                'arguments' => '{}',
                'status' => 'completed',
            ],
        ],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}

test('default store true still retains replay blocks with encrypted reasoning', function (): void {
    Configure::write('Ai.providers.openai.store', true);

    aiHttpFake([
        'api.openai.com/*' => aiHttpSequence([
            fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $assistant = $response->messages->filter(fn($m): bool => $m instanceof AssistantMessage)->first();
    $blocks = collection($assistant->replayBlocks);

    expect($blocks->filter(fn($b): bool => ($b['type'] ?? null) === 'reasoning')->first())->toMatchArray(['id' => 'rs_1', 'encrypted_content' => 'enc-blob-1'])
        ->and(($blocks->filter(fn($b): bool => ($b['type'] ?? null) === 'function_call')->first()['call_id'] ?? null))->toBe('call_1');
});

test('stateless tool follow up drops file search calls but keeps the surrounding reasoning', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpSequence([
            aiHttpResponse([
                'id' => 'resp_tool_1',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [
                    ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
                    ['type' => 'file_search_call', 'id' => 'fs_1', 'status' => 'completed', 'queries' => ['numbers'], 'results' => null],
                    ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}', 'status' => 'completed'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $recorded = aiHttpRecorded();
    $input = collect(json_decode((string)$recorded[1][0]->body(), true)['input']);

    expect($input->filter(fn($i): bool => ($i['type'] ?? null) !== null)->extract('type')->toList())
        ->toBe(['reasoning', 'function_call', 'function_call_output'])
        ->and($input->firstMatch(['type' => 'reasoning']))->toMatchArray(['id' => 'rs_1', 'encrypted_content' => 'enc-blob-1']);

    $blocks = $response->messages->filter(fn($m): bool => $m instanceof AssistantMessage)->first()->replayBlocks;

    expect(collect($blocks)->firstMatch(['type' => 'file_search_call'])['id'] ?? null)
        ->toBe('fs_1');
});
