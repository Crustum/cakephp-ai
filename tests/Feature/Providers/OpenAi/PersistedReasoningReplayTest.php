<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Fixtures\Agents\RememberingToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

function openAiReasoningToolTurn(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'resp_tool_1',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}', 'status' => 'completed'],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function openAiTextTurn(string $text): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'resp_' . uniqid(),
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

beforeEach(function (): void {
    Configure::write('Ai.conversations.generate_title', false);
    Configure::write('Ai.providers.openai.key', 'test-key');
    Configure::write('Ai.providers.openai.store', false);
});

test('a stored reasoning turn replays its tool call without the item id its reasoning no longer backs', function (): void {
    aiHttpFake(['api.openai.com/*' => aiHttpSequence([
        openAiReasoningToolTurn(),
        openAiTextTurn('The number is 72019'),
        openAiTextTurn('Still 72019'),
    ])]);

    $agent = (new RememberingToolUsingAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001']);

    $agent->prompt('Generate a number', provider: 'openai');

    // The stored turn no longer carries the reasoning item, so the replay must not name the function call item either.
    $agent->prompt('Again', provider: 'openai');

    $recorded = aiHttpRecorded();
    $replayed = $recorded[count($recorded) - 1][0];

    $input = collect(json_decode($replayed->body(), true)['input']);

    $functionCalls = $input->filter(fn($i): bool => ($i['type'] ?? '') === 'function_call')->toList();

    expect($functionCalls)->toHaveCount(1)
        ->and($functionCalls[0])->toMatchArray(['call_id' => 'call_1', 'name' => 'FixedNumberGenerator'])
        ->and($functionCalls[0])->not->toHaveKey('id')
        ->and($input->filter(fn($i): bool => ($i['type'] ?? '') === 'reasoning')->toList())->toBe([]);
});

test('a live reasoning turn still names its function call item within the same run', function (): void {
    aiHttpFake(['api.openai.com/*' => aiHttpSequence([
        openAiReasoningToolTurn(),
        openAiTextTurn('The number is 72019'),
    ])]);

    (new RememberingToolUsingAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->prompt('Generate a number', provider: 'openai');

    $recorded = aiHttpRecorded();
    $followUp = json_decode($recorded[1][0]->body(), true);

    $input = $followUp['input'];

    $reasoningIndex = null;
    $callIndex = null;

    foreach ($input as $index => $item) {
        if (($item['type'] ?? '') === 'reasoning') {
            $reasoningIndex = $index;
        }

        if (($item['id'] ?? '') === 'fc_1') {
            $callIndex = $index;
        }
    }

    expect($reasoningIndex)->not->toBeNull()
        ->and($callIndex)->toBe($reasoningIndex + 1);
});
