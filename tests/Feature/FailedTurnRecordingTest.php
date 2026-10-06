<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Http\Client\Exception\ClientException;
use Crustum\Ai\Ai;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Providers\AnthropicProvider;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingFailingToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingToolUsingAgent;
use Crustum\Ai\Test\Support\Database\ConversationTable;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.conversations.generate_title', false);
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');

    Ai::manager()->setConversationStore(new DatabaseConversationStore());
});

function failedAnthropicToolTurn(string $id, string $name = 'FixedNumberGenerator'): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'msg_' . $id,
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object)[]]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function failedAnthropicToolStream(string $id, string $name = 'FixedNumberGenerator'): string
{
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object)[]]],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
    ];

    return implode("\n\n", array_map(fn(array $event): string => 'data: ' . json_encode($event), $events)) . "\n\n";
}

function failedAssistantRow(): ?object
{
    return ConversationTable::first('agent_conversation_messages', ['role' => 'assistant']);
}

function failedServerError(): AiHttpResponseDefinition
{
    return aiHttpResponse(['error' => ['message' => 'Server error']], 500);
}

test('a turn that dies after a tool ran keeps the step and its result', function (): void {
    aiHttpFake(['api.anthropic.com/*' => aiHttpSequence([
        failedAnthropicToolTurn('toolu_1'),
        failedServerError(),
        failedServerError(),
        failedServerError(),
    ])]);

    expect(fn(): mixed => (new RememberingToolUsingAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->prompt('Go', provider: 'anthropic'))
        ->toThrow(ClientException::class);

    $row = failedAssistantRow();
    $steps = json_decode((string)$row->steps, true);

    expect($row->status)->toBe('failed')
        ->and(json_decode((string)$row->meta, true)['error'])->not->toBeEmpty()
        ->and($steps)->toHaveCount(1)
        ->and($steps[0]['tool_calls'])->toHaveCount(1)
        ->and($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'toolu_1', 'result' => '72019'])
        ->and(ConversationTable::query('agent_conversation_messages')->where(['role' => 'user'])->execute()->fetchAll('assoc'))->toHaveCount(1);
});

test('a failed turn replays its answered call and flags the one it never answered', function (): void {
    aiHttpFake(['api.anthropic.com/*' => aiHttpSequence([
        failedAnthropicToolTurn('toolu_1'),
        failedServerError(),
        failedServerError(),
        failedServerError(),
    ])]);

    $agent = (new RememberingToolUsingAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001']);

    expect(fn(): mixed => $agent->prompt('Go', provider: 'anthropic'))->toThrow(ClientException::class);

    $conversationId = ConversationTable::first('agent_conversations')->id;

    $messages = (new DatabaseConversationStore())->getLatestConversationMessages($conversationId, 10)->toList();

    $last = $messages[count($messages) - 1];

    expect($last)->toBeInstanceOf(ToolResultMessage::class)
        ->and($last->toolResults)->toHaveCount(1)
        ->and($last->toolResults->first()->id)->toBe('toolu_1')
        ->and($last->toolResults->first()->result)->toBe('72019')
        ->and($messages[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[1]->toolCalls)->toHaveCount(1)
        ->and($messages[1]->toolCalls->first()->id)->toBe('toolu_1');
});

test('a turn that dies before any step records nothing', function (): void {
    aiHttpFake(['api.anthropic.com/*' => failedServerError()]);

    expect(fn(): mixed => (new RememberingAssistantAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->prompt('Go', provider: 'anthropic'))
        ->toThrow(ClientException::class);

    expect(ConversationTable::query('agent_conversation_messages')->execute()->fetchAll('assoc'))->toBe([])
        ->and(ConversationTable::query('agent_conversations')->execute()->fetchAll('assoc'))->toBe([]);
});

test('an agent with nothing to remember records nothing when it dies', function (): void {
    aiHttpFake(['api.anthropic.com/*' => aiHttpSequence([
        failedAnthropicToolTurn('toolu_1'),
        failedServerError(),
        failedServerError(),
        failedServerError(),
    ])]);

    expect(fn(): mixed => (new RememberingToolUsingAgent())->prompt('Go', provider: 'anthropic'))
        ->toThrow(ClientException::class);

    expect(ConversationTable::query('agent_conversation_messages')->execute()->fetchAll('assoc'))->toBe([]);
});

test('an attempt that fails over to another provider leaves no failed turn behind', function (): void {
    Configure::write('Ai.providers.primary', ['className' => AnthropicProvider::class, 'key' => 'test-key']);
    Configure::write('Ai.providers.backup', ['className' => AnthropicProvider::class, 'key' => 'test-key']);

    aiHttpFake(['api.anthropic.com/*' => aiHttpSequence([
        aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
        aiHttpResponse([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'Done.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ])]);

    (new RememberingAssistantAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->prompt('Go', provider: ['primary', 'backup']);

    $statuses = [];

    foreach (ConversationTable::query('agent_conversation_messages')->where(['role' => 'assistant'])->execute()->fetchAll('assoc') as $row) {
        $statuses[] = $row['status'];
    }

    expect($statuses)->toBe(['completed']);
});

test('a stream that dies mid-flight keeps the steps it completed', function (): void {
    aiHttpFake(['api.anthropic.com/*' => aiHttpSequence([
        aiHttpResponse(failedAnthropicToolStream('toolu_1'), 200, ['Content-Type' => 'text/event-stream']),
        failedServerError(),
        failedServerError(),
        failedServerError(),
    ])]);

    $stream = (new RememberingToolUsingAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->stream('Go', provider: 'anthropic');

    expect(function () use ($stream): void {
        foreach ($stream as $event) {
        }
    })->toThrow(ClientException::class);

    $row = failedAssistantRow();
    $steps = json_decode((string)$row->steps, true);

    expect($row->status)->toBe('failed')
        ->and($steps)->toHaveCount(1)
        ->and($steps[0]['tool_calls'])->toHaveCount(1)
        ->and($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'toolu_1', 'result' => '72019']);
});

test('a stream that dies records the conversation the client was already handed', function (): void {
    aiHttpFake(['api.anthropic.com/*' => aiHttpSequence([
        aiHttpResponse(failedAnthropicToolStream('toolu_1'), 200, ['Content-Type' => 'text/event-stream']),
        failedServerError(),
        failedServerError(),
        failedServerError(),
    ])]);

    $stream = (new RememberingToolUsingAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->stream('Go', provider: 'anthropic');

    $surfaced = $stream->conversationId;

    expect(function () use ($stream): void {
        foreach ($stream as $event) {
        }
    })->toThrow(ClientException::class);

    expect($surfaced)->not->toBeNull()
        ->and(ConversationTable::first('agent_conversations')->id)->toBe($surfaced);
});

test('a turn that dies keeps the text it had already produced', function (): void {
    aiHttpFake(['api.anthropic.com/*' => aiHttpSequence([
        aiHttpResponse([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => 'Let me generate that number.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object)[]],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
        failedServerError(),
        failedServerError(),
        failedServerError(),
    ])]);

    expect(fn(): mixed => (new RememberingToolUsingAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->prompt('Go', provider: 'anthropic'))
        ->toThrow(ClientException::class);

    expect(failedAssistantRow()->content)->toBe('Let me generate that number.');
});

test('a step that dies on its second call keeps the result of the first', function (): void {
    aiHttpFake(['api.anthropic.com/*' => aiHttpResponse([
        'id' => 'msg_1',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'SecretCodeGenerator', 'input' => (object)[]],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'FixedNumberGenerator', 'input' => (object)[]],
        ],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ])]);

    $agent = (new RememberingFailingToolAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001']);

    expect(fn(): mixed => $agent->prompt('Go', provider: 'anthropic'))->toThrow(Exception::class, 'Forced to throw exception.');

    $steps = json_decode((string)failedAssistantRow()->steps, true);

    expect($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'toolu_1', 'result' => 'ZEBRA-4417'])
        ->and($steps[0]['tool_calls'][1])->not->toHaveKey('result');

    $messages = (new DatabaseConversationStore())->getLatestConversationMessages(
        ConversationTable::first('agent_conversations')->id,
        10,
    )->toList();

    $results = $messages[count($messages) - 1]->toolResults->map(fn($result): string => (string)$result->result)->toList();

    expect($results)->toBe([
        'ZEBRA-4417',
        'This tool call was interrupted before a result was recorded, so it may or may not have run.',
    ]);
});

test('a conversation opened by a failed turn is titled the way a completed one is', function (): void {
    Configure::write('Ai.conversations.generate_title', true);

    aiHttpFake(['api.anthropic.com/*' => aiHttpSequence([
        failedAnthropicToolTurn('toolu_1'),
        failedServerError(),
        failedServerError(),
        failedServerError(),
    ])]);

    $prompt = 'Generate a number for the quarterly report and then explain how you arrived at it in detail';

    expect(fn(): mixed => (new RememberingToolUsingAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->prompt($prompt, provider: 'anthropic'))
        ->toThrow(ClientException::class);

    expect((string)ConversationTable::first('agent_conversations')->title)->toContain('arrived at it');
});
