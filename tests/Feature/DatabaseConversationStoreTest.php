<?php
declare(strict_types=1);

use Cake\Collection\Collection;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\Routing\Router;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Contracts\PaginatesConversations;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\ResolvesPendingApprovals;
use Crustum\Ai\Contracts\VerifiesConversationOwnership;
use Crustum\Ai\Enums\MessageStatus;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\StoredDocument;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ProviderToolCall;
use Crustum\Ai\Responses\Data\Step;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Storage\ConversationMessagePage;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Storage\StoredMessage;
use Crustum\Ai\Streaming\Event\Citation as CitationEvent;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingToolUsingAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Database\ConversationFixtures;
use Crustum\Ai\Test\Support\Database\ConversationSchema;
use Crustum\Ai\Test\Support\Database\ConversationTable;
use JMac\Testing\Double;
use TestApp\Model\Entity\User;

beforeEach(function (): void {
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');
});

test('it writes conversations to the default tables', function (): void {
    $store = new DatabaseConversationStore();

    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Hello');

    expect(ConversationTable::exists('agent_conversations', ['id' => $conversationId, 'title' => 'Hello']))->toBeTrue();
});

test('it paginates conversation messages newest first, scoped to the conversation', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Target transcript');
    $otherConversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Other transcript');

    insertStoredConversationMessages($conversationId, [ConversationFixtures::MESSAGE_ONE, ConversationFixtures::MESSAGE_THREE, ConversationFixtures::MESSAGE_FIVE]);
    insertStoredConversationMessages($otherConversationId, [ConversationFixtures::MESSAGE_TWO, ConversationFixtures::MESSAGE_FOUR]);

    $page = $store->paginateConversationMessages($conversationId, 10);

    expect($store)->toBeInstanceOf(PaginatesConversations::class)
        ->and($page)->toBeInstanceOf(ConversationMessagePage::class)
        ->and($page->items())->toContainOnlyInstancesOf(StoredMessage::class)
        ->and(array_map(fn(StoredMessage $message): string => $message->id, $page->items()))->toBe([ConversationFixtures::MESSAGE_FIVE, ConversationFixtures::MESSAGE_THREE, ConversationFixtures::MESSAGE_ONE]);
});

test('it verifies which participant a conversation was stored for', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Mine');

    expect($store)->toBeInstanceOf(VerifiesConversationOwnership::class)
        ->and($store->conversationBelongsTo($conversationId, 'user', ConversationFixtures::PARTICIPANT))->toBeTrue()
        ->and($store->conversationBelongsTo($conversationId, 'user', ConversationFixtures::OTHER_PARTICIPANT))->toBeFalse()
        ->and($store->conversationBelongsTo($conversationId, 'team', ConversationFixtures::PARTICIPANT))->toBeFalse();
});

test('it matches a stored participant key against the same key', function (): void {
    $store = new DatabaseConversationStore();

    expect($store->conversationBelongsTo($store->storeConversation('user', ConversationFixtures::OTHER_PARTICIPANT, 'Mine'), 'user', ConversationFixtures::OTHER_PARTICIPANT))->toBeTrue()
        ->and($store->conversationBelongsTo($store->storeConversation('user', ConversationFixtures::OTHER_PARTICIPANT, 'Mine'), 'user', ConversationFixtures::OTHER_PARTICIPANT))->toBeTrue();
});

test('it refuses a half-matching participant', function (): void {
    $store = new DatabaseConversationStore();
    $ownerless = $store->storeConversation(null, null, 'Ownerless');
    $owned = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Owned');

    expect($store->conversationBelongsTo($ownerless, 'user', null))->toBeFalse()
        ->and($store->conversationBelongsTo($ownerless, null, ConversationFixtures::PARTICIPANT))->toBeFalse()
        ->and($store->conversationBelongsTo($owned, 'user', null))->toBeFalse()
        ->and($store->conversationBelongsTo($owned, null, ConversationFixtures::PARTICIPANT))->toBeFalse();
});

test('it refuses a conversation that does not exist', function (): void {
    expect((new DatabaseConversationStore())->conversationBelongsTo(ConversationFixtures::MISSING_CONVERSATION, 'user', ConversationFixtures::PARTICIPANT))->toBeFalse();
});

test('it matches an ownerless conversation only to a null participant', function (): void {
    $store = new DatabaseConversationStore();
    $ownerless = $store->storeConversation(null, null, 'Ownerless');

    expect($store->conversationBelongsTo($ownerless, null, null))->toBeTrue()
        ->and($store->conversationBelongsTo($ownerless, 'user', ConversationFixtures::PARTICIPANT))->toBeFalse();
});

test('it reports the tool calls a paused turn is waiting on', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Paused');

    insertPausedConversationTurn($conversationId, ConversationFixtures::MESSAGE_ONE, [
        ['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'a.txt']],
        ['id' => 'call-2', 'name' => 'SendEmail', 'arguments' => ['to' => 'a@b.test']],
    ], ['call-1' => 'Deletes a file.', 'call-2' => null]);

    $pending = $store->pendingApprovalsFor($conversationId);

    expect($store)->toBeInstanceOf(ResolvesPendingApprovals::class)
        ->and($pending)->toHaveCount(2)
        ->and($pending[0])->toBeInstanceOf(PendingApproval::class)
        ->and($pending[0]->id)->toBe('call-1')
        ->and($pending[0]->tool)->toBe('DeleteFile')
        ->and($pending[0]->arguments)->toBe(['path' => 'a.txt'])
        ->and($pending[0]->reason)->toBe('Deletes a file.')
        ->and($pending[1]->reason)->toBeNull();
});

test('it drops a call that already has a result', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Resumed');

    insertPausedConversationTurn($conversationId, ConversationFixtures::MESSAGE_ONE, [
        ['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []],
        ['id' => 'call-2', 'name' => 'SendEmail', 'arguments' => []],
    ], ['call-1' => null, 'call-2' => null], [
        ['id' => 'call-1', 'name' => 'DeleteFile', 'result' => 'Deleted.'],
    ]);

    expect(array_map(fn($approval): string => $approval->id, $store->pendingApprovalsFor($conversationId)))->toBe(['call-2']);
});

test('it reports nothing when the newest turn is not paused', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Answered');

    insertStoredConversationMessages($conversationId, [ConversationFixtures::MESSAGE_ONE]);

    expect($store->pendingApprovalsFor($conversationId))->toBe([])
        ->and($store->pendingApprovalsFor(ConversationFixtures::MISSING_CONVERSATION))->toBe([]);
});

test('it decodes the stored JSON columns', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Transcript');

    ConversationSchema::saveMessage([
        ...storedConversationMessageAttributes(ConversationFixtures::MESSAGE_ONE, $conversationId, 'Saved the note.'),
        'role' => 'assistant',
        'meta' => ['provider' => 'openai', 'citations' => [['url' => 'https://cakephp.org']]],
        'steps' => [assistantStep([['id' => 'call-1', 'name' => 'save_note', 'arguments' => ['a' => 1]]])],
        'usage_data' => ['input_tokens' => 12],
    ]);

    $message = $store->paginateConversationMessages($conversationId, 1)->items()[0];

    expect($message->meta['provider'])->toBe('openai')
        ->and($message->meta['citations'][0]['url'])->toBe('https://cakephp.org')
        ->and($message->toolCalls()[0]['name'])->toBe('save_note')
        ->and($message->usage['input_tokens'])->toBe(12)
        ->and($message->toolResults())->toBe([])
        ->and($message->status)->toBe(MessageStatus::Completed)
        ->and($message->createdAt)->toBeInstanceOf(DateTimeInterface::class);
});

test('a stored tool result is narrowed to its own keys so provider replay state stays out of a rendered transcript', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Transcript');

    ConversationSchema::saveMessage([
        ...storedConversationMessageAttributes(ConversationFixtures::MESSAGE_ONE, $conversationId, 'Saved the note.'),
        'role' => 'assistant',
        'steps' => [assistantStep(
            [['id' => 'call-1', 'name' => 'save_note', 'arguments' => ['a' => 1], 'reasoning_id' => 'rs_1', 'reasoning_encrypted_content' => 'gAAAAA']],
            [['id' => 'call-1', 'result' => 'Saved']],
        )],
    ]);

    $message = $store->paginateConversationMessages($conversationId, 1)->items()[0];

    expect($message->toolResults())->toBe([
        ['id' => 'call-1', 'name' => 'save_note', 'arguments' => ['a' => 1], 'result' => 'Saved'],
    ])->and($message->toolCalls()[0])->toHaveKey('reasoning_encrypted_content');
});

test('it advances to the next cursor page', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Transcript');

    insertStoredConversationMessages($conversationId, [ConversationFixtures::MESSAGE_ONE, ConversationFixtures::MESSAGE_TWO, ConversationFixtures::MESSAGE_THREE, ConversationFixtures::MESSAGE_FOUR, ConversationFixtures::MESSAGE_FIVE]);

    $firstPage = $store->paginateConversationMessages($conversationId, 2);

    $previous = Router::getRequest();

    try {
        Router::setRequest(new ServerRequest(['query' => ['cursor' => $firstPage->nextCursor()?->encode()]]));

        $secondPage = $store->paginateConversationMessages($conversationId, 2);

        expect(array_map(fn(StoredMessage $message): string => $message->id, $firstPage->items()))->toBe([ConversationFixtures::MESSAGE_FIVE, ConversationFixtures::MESSAGE_FOUR])
            ->and(array_map(fn(StoredMessage $message): string => $message->id, $secondPage->items()))->toBe([ConversationFixtures::MESSAGE_THREE, ConversationFixtures::MESSAGE_TWO]);
    } finally {
        Router::setRequest($previous ?? new ServerRequest());
    }
});

test('it reads the cursor from the given query parameter name', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Transcript');

    insertStoredConversationMessages($conversationId, [ConversationFixtures::MESSAGE_ONE, ConversationFixtures::MESSAGE_TWO, ConversationFixtures::MESSAGE_THREE, ConversationFixtures::MESSAGE_FOUR, ConversationFixtures::MESSAGE_FIVE]);

    $firstPage = $store->paginateConversationMessages($conversationId, 2, 'support');

    $previous = Router::getRequest();

    try {
        Router::setRequest(new ServerRequest(['query' => [
            'support' => $firstPage->nextCursor()?->encode(),
            'cursor' => 'ignored',
        ]]));

        $secondPage = $store->paginateConversationMessages($conversationId, 2, 'support');

        expect(array_map(fn(StoredMessage $message): string => $message->id, $secondPage->items()))->toBe([ConversationFixtures::MESSAGE_THREE, ConversationFixtures::MESSAGE_TWO]);
    } finally {
        Router::setRequest($previous ?? new ServerRequest());
    }
});

test('it accepts a cursor passed directly, without a request', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Transcript');

    insertStoredConversationMessages($conversationId, [ConversationFixtures::MESSAGE_ONE, ConversationFixtures::MESSAGE_TWO, ConversationFixtures::MESSAGE_THREE, ConversationFixtures::MESSAGE_FOUR, ConversationFixtures::MESSAGE_FIVE]);

    $firstPage = $store->paginateConversationMessages($conversationId, 2);

    $secondPage = $store->paginateConversationMessages($conversationId, 2, cursor: $firstPage->nextCursor());

    expect(array_map(fn(StoredMessage $message): string => $message->id, $secondPage->items()))->toBe([ConversationFixtures::MESSAGE_THREE, ConversationFixtures::MESSAGE_TWO]);
});

test('it finds the latest conversation by participant type and id', function (): void {
    $store = new DatabaseConversationStore();
    $type = User::class;

    $older = $store->storeConversation($type, ConversationFixtures::STREAMING_PARTICIPANT, 'Older');
    $newer = $store->storeConversation($type, ConversationFixtures::STREAMING_PARTICIPANT, 'Newer');
    $store->storeConversation($type, '88888888-8888-4888-8888-888888888888', 'Other');
    $store->storeConversation('Other\\Type', ConversationFixtures::STREAMING_PARTICIPANT, 'Wrong type');

    $insertMessage = function (string $id, string $conversationId) use ($type): void {
        ConversationSchema::saveMessage([
            'id' => $id,
            'conversation_id' => $conversationId,
            'participant_type' => $type,
            'participant_id' => ConversationFixtures::STREAMING_PARTICIPANT,
            'agent' => ToolUsingAgent::class,
            'role' => 'user',
            'content' => 'Hello',
            'attachments' => [],
            'steps' => [],
            'usage_data' => [],
            'meta' => [],
        ]);
    };

    $insertMessage('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $older);
    $insertMessage('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $newer);

    expect($store->latestConversationId($type, ConversationFixtures::STREAMING_PARTICIPANT, ToolUsingAgent::class))->toBe($newer)
        ->and($store->latestConversationId($type, '88888888-8888-4888-8888-888888888888', ToolUsingAgent::class))->toBeNull()
        ->and($store->latestConversationId('Other\\Type', ConversationFixtures::STREAMING_PARTICIPANT, ToolUsingAgent::class))->toBeNull();
});

test('it scopes the latest conversation lookup to conversations the agent has participated in', function (): void {
    $store = new DatabaseConversationStore();

    $first = $store->storeConversation('user', ConversationFixtures::LATEST_PARTICIPANT, 'First');
    $second = $store->storeConversation('user', ConversationFixtures::LATEST_PARTICIPANT, 'Second');

    $insertMessage = function (string $id, string $conversationId, string $agent): void {
        ConversationSchema::saveMessage([
            'id' => $id,
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => ConversationFixtures::LATEST_PARTICIPANT,
            'agent' => $agent,
            'role' => 'user',
            'content' => 'Hello',
            'attachments' => [],
            'steps' => [],
            'usage_data' => [],
            'meta' => [],
        ]);
    };

    $insertMessage('c0000000-0000-4000-8000-000000000001', $first, ToolUsingAgent::class);
    $insertMessage('c0000000-0000-4000-8000-000000000002', $second, RememberingToolUsingAgent::class);

    expect($store->latestConversationId('user', ConversationFixtures::LATEST_PARTICIPANT, ToolUsingAgent::class))->toBe($first)
        ->and($store->latestConversationId('user', ConversationFixtures::LATEST_PARTICIPANT, RememberingToolUsingAgent::class))->toBe($second)
        ->and($store->latestConversationId('user', ConversationFixtures::MISSING_PARTICIPANT, ToolUsingAgent::class))->toBeNull();

    $insertMessage('c0000000-0000-4000-8000-000000000003', $second, ToolUsingAgent::class);

    expect($store->latestConversationId('user', ConversationFixtures::LATEST_PARTICIPANT, ToolUsingAgent::class))->toBe($second);
});

test('it writes to overridden table names from config', function (): void {
    Configure::write('Ai.conversations.tables.conversations', 'custom_conversations');
    Configure::write('Ai.conversations.tables.messages', 'custom_conversation_messages');

    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Hello');

    expect(ConversationTable::exists('custom_conversations', ['id' => $conversationId]))->toBeTrue()
        ->and(aiDbExists('agent_conversations', ['id' => $conversationId]))->toBeFalse();
});

test('it routes queries through the configured connection', function (): void {
    ConversationSchema::create('secondary');

    $store = new DatabaseConversationStore('secondary');
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Hello');

    expect(ConversationTable::exists('agent_conversations', ['id' => $conversationId], 'secondary'))->toBeTrue()
        ->and(aiDbExists('agent_conversations', ['id' => $conversationId], 'test'))->toBeFalse();
})->after(function (): void {
    Configure::write('Ai.conversations.connection', 'test');
    ConnectionManager::get('secondary')->execute('DELETE FROM agent_conversation_messages');
    ConnectionManager::get('secondary')->execute('DELETE FROM agent_conversations');
});

test('it stores one step per model round-trip from a remembered agent prompt', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            aiHttpResponse(storeTestGeminiInteraction([[
                'type' => 'function_call',
                'id' => 'call_123',
                'name' => 'FixedNumberGenerator',
                'arguments' => (object)[],
            ]])),
            aiHttpResponse(storeTestGeminiInteraction([[
                'type' => 'model_output',
                'content' => [['type' => 'text', 'text' => 'The number is 72019']],
            ]])),
        ]),
    ]);

    Configure::write('Ai.providers.gemini.key', 'test-key');

    $user = (object)['id' => ConversationFixtures::PARTICIPANT];
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', $user->id, 'Tool conversation');

    (new RememberingToolUsingAgent())
        ->continue($conversationId, $user)
        ->prompt('Generate a random number', provider: 'gemini');

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($messagesTable->find()->where(['role' => 'user'])->firstOrFail()->steps)->toBe([])
        ->and($record->content)->toBe('The number is 72019')
        ->and($record->steps)->toHaveCount(2)
        ->and($record->steps[0])->toMatchArray(['replay_blocks' => []])
        ->and($record->steps[0]['tool_calls'])->toHaveCount(1)
        ->and($record->steps[0]['tool_calls'][0])->toMatchArray(['id' => 'call_123', 'name' => 'FixedNumberGenerator', 'result' => '72019'])
        ->and($record->steps[1])->toMatchArray(['tool_calls' => [], 'replay_blocks' => []]);
});

function storeTestGeminiInteraction(array $steps): array
{
    return [
        'id' => 'int_store',
        'model' => 'gemini-3.5-flash',
        'status' => 'completed',
        'steps' => $steps,
        'usage' => ['total_input_tokens' => 10, 'total_output_tokens' => 5, 'total_tokens' => 15],
    ];
}

test('it preserves the gemini thought signature across a persisted tool conversation', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(storeTestGeminiInteraction([
                ['type' => 'thought', 'summary' => [['type' => 'text', 'text' => 'Thinking.']], 'signature' => 'sig_persist_777'],
                ['type' => 'function_call', 'id' => 'call_123', 'name' => 'FixedNumberGenerator', 'arguments' => (object)[]],
            ])),
            aiHttpResponse(storeTestGeminiInteraction([[
                'type' => 'model_output',
                'content' => [['type' => 'text', 'text' => 'The number is 72019']],
            ]])),
            aiHttpResponse(storeTestGeminiInteraction([[
                'type' => 'model_output',
                'content' => [['type' => 'text', 'text' => 'The second number is 99']],
            ]])),
        ]),
    ]);

    Configure::write('Ai.providers.gemini.key', 'test-key');

    $user = (object)['id' => ConversationFixtures::PARTICIPANT];
    $conversationId = (new DatabaseConversationStore())->storeConversation('user', $user->id, 'Tool conversation');

    (new RememberingToolUsingAgent())->continue($conversationId, $user)->prompt('Generate a random number', provider: 'gemini');

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();
    $storedCalls = is_string($record->tool_calls) ? json_decode($record->tool_calls, true) : $record->tool_calls;

    expect($storedCalls[0]['thought_signature'])->toBe('sig_persist_777')
        ->and($record->steps[0]['replay_blocks'])->toBe([]);

    (new RememberingToolUsingAgent())->continue($conversationId, $user)->prompt('Generate another', provider: 'gemini');

    $recorded = aiHttpRecorded();
    $signatures = array_column(array_filter(
        $recorded[count($recorded) - 1][0]->data()['input'],
        fn(array $step): bool => $step['type'] === 'thought',
    ), 'signature');

    expect($signatures)->toBe(['sig_persist_777']);
});

test('it stores a response built without steps as a single step of lists', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Check my order status.',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = new AgentResponse('invocation-id', 'The order has shipped.', new TextUsage(), new Meta());
    $response->toolCalls = new Collection([
        2 => new ToolCall('call-1', 'lookup_order', ['id' => 1]),
        8 => new ToolCall('call-2', 'lookup_carrier', ['id' => 1]),
    ]);
    $response->toolResults = new Collection([
        2 => new ToolResult('call-1', 'lookup_order', ['id' => 1], ['status' => 'shipped']),
        8 => new ToolResult('call-2', 'lookup_carrier', ['id' => 1], ['carrier' => 'UPS']),
    ]);

    $store->storeAssistantMessage($conversationId, null, ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->steps)->toHaveCount(1)
        ->and(array_is_list($record->steps[0]['tool_calls']))->toBeTrue()
        ->and($record->steps[0]['tool_calls'])->toHaveCount(2)
        ->and($record->steps[0]['tool_calls'][0])->toMatchArray(['id' => 'call-1', 'result' => ['status' => 'shipped']])
        ->and($record->steps[0]['tool_calls'][1])->toMatchArray(['id' => 'call-2', 'result' => ['carrier' => 'UPS']]);
});

test('it round trips tool result failure status through storage', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Where is Berlin?',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = new AgentResponse('invocation-id', '', new TextUsage(), new Meta());
    $response->toolCalls = new Collection([
        new ToolCall('call-1', 'query-resources', []),
    ]);
    $response->toolResults = new Collection([
        new ToolResult('call-1', 'query-resources', [], 'Tool not found', failed: true),
    ]);

    $store->storeAssistantMessage($conversationId, null, ConversationFixtures::PARTICIPANT, $prompt, $response);

    $result = $store->getLatestConversationMessages($conversationId, 10)
        ->filter(fn($message): bool => $message instanceof ToolResultMessage)
        ->first()
        ?->toolResults
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->successful())->toBeFalse()
        ->and($result->error())->toBe('Tool not found');
});

test('it stores a tool result id on the call that made it', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Write the file',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = (new AgentResponse('invocation-id', 'Wrote it.', new TextUsage(), new Meta('openai', 'gpt-5')))
        ->withSteps(collection([
            new Step(
                'Wrote it.',
                [new ToolCall('call-1', 'WriteFile', ['path' => 'a.txt', 'contents' => 'alpha'], 'result-1')],
                [new ToolResult('call-1', 'WriteFile', ['path' => 'a.txt', 'contents' => 'alpha'], 'Wrote 5 bytes.', 'result-1')],
                FinishReason::Stop,
                new TextUsage(),
                new Meta('openai', 'gpt-5'),
                '',
                [],
            ),
        ]));

    $store->storeAssistantMessage($conversationId, null, ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->steps[0]['tool_calls'])->toHaveCount(1)
        ->and($record->steps[0]['tool_calls'][0])->toMatchArray([
            'id' => 'call-1',
            'name' => 'WriteFile',
            'arguments' => ['path' => 'a.txt', 'contents' => 'alpha'],
            'result' => 'Wrote 5 bytes.',
            'result_id' => 'result-1',
        ]);

    $result = $store->getLatestConversationMessages($conversationId, 10)
        ->filter(fn($message): bool => $message instanceof ToolResultMessage)
        ->first()
        ?->toolResults
        ->first();

    expect($result->arguments)->toBe(['path' => 'a.txt', 'contents' => 'alpha'])
        ->and($result->result)->toBe('Wrote 5 bytes.')
        ->and($result->resultId)->toBe('result-1');
});

test('it treats tool results stored before the failed flag as successful', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'query-resources', 'arguments' => []]],
            [['id' => 'call-1', 'name' => 'query-resources', 'arguments' => [], 'result' => 'Berlin', 'result_id' => null]],
        ),
    ]);

    $result = $store->getLatestConversationMessages($conversationId, 10)
        ->filter(fn($message): bool => $message instanceof ToolResultMessage)
        ->first()
        ?->toolResults
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->successful())->toBeTrue()
        ->and($result->error())->toBeNull();
});

test('it replays a completed multi-step turn with each result answering its own step', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, 'Done.', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result_id' => 'result-1']],
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a', 'result_id' => 'result-1']],
            [],
            'Reading a first.',
        ),
        assistantStep(
            [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]],
            [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result' => 'Deleted b']],
        ),
        assistantStep(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(5)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('Reading a first.')
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls->first()->id)->toBe('call-1')
        ->and($messages[0]->toolCalls->first()->resultId)->toBe('result-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults)->toHaveCount(1)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1')
        ->and($messages[1]->toolResults->first()->resultId)->toBe('result-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('')
        ->and($messages[2]->toolCalls)->toHaveCount(1)
        ->and($messages[2]->toolCalls->first()->id)->toBe('call-2')
        ->and($messages[3])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[3]->toolResults)->toHaveCount(1)
        ->and($messages[3]->toolResults->first()->id)->toBe('call-2')
        ->and($messages[4])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[4]->content)->toBe('Done.')
        ->and($messages[4]->toolCalls)->toBeEmpty();
});

test('it drops the unexecuted calls of a step-limited tail but keeps its text', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, 'I ran out of steps.', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]]],
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']]],
        ),
        assistantStep(
            [['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1]]],
            [],
            [['type' => 'text', 'text' => 'I ran out of steps.'], ['type' => 'tool_use', 'id' => 'call-2']],
        ),
    ], meta: ['provider' => 'anthropic']);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls->first()->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('I ran out of steps.')
        ->and($messages[2]->toolCalls)->toBeEmpty()
        ->and($messages[2]->replayBlocks)->toBe([]);
});

test('it replays every step of a paused turn with its replay blocks tagged by the provider that made them', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, 'Read a, deleting b', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a']]],
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
            [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        ),
        assistantStep(
            [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]],
            [],
            [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
        ),
    ], ['call-2' => 'Destructive.'], ['provider' => 'anthropic']);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->replayBlocksProvider)->toBe('anthropic')
        ->and($messages[0]->replayBlocks)->toHaveCount(2)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls->first()->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults)->toHaveCount(1)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Read a, deleting b')
        ->and($messages[2]->replayBlocksProvider)->toBe('anthropic')
        ->and($messages[2]->replayBlocks)->toHaveCount(2);
});

test('it drops resultless tool calls and replays only the final assistant text', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => ConversationFixtures::MESSAGE_ONE,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => ConversationFixtures::PARTICIPANT,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'The order has shipped.',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result_id' => 'result-1'],
        ],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('The order has shipped.')
        ->and($messages[0]->toolCalls->isEmpty())->toBeTrue();
});

test('it drops resultless tool calls with no final text entirely', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => ConversationFixtures::MESSAGE_ONE,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => ConversationFixtures::PARTICIPANT,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result_id' => 'result-1'],
        ],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toBeEmpty();
});

test('it still rehydrates reasoning encrypted content stored on legacy tool calls', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Reasoning conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, 'Looking that up.', [
        assistantStep(
            [[
                'id' => 'call-1',
                'name' => 'lookup_order',
                'arguments' => ['id' => 1],
                'reasoning_id' => 'rs_1',
                'reasoning_summary' => [],
                'reasoning_encrypted_content' => 'enc-blob-1',
            ]],
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']]],
        ),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages[0]->toolCalls->first())
        ->reasoningId->toBe('rs_1')
        ->reasoningEncryptedContent->toBe('enc-blob-1');
});

test('user messages with stored attachments are rehydrated as UserMessage', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Attachment conversation');

    ConversationSchema::saveMessage([
        'id' => ConversationFixtures::MESSAGE_ONE,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => ConversationFixtures::PARTICIPANT,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Describe this image.',
        'attachments' => [
            ['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg', 'mime' => 'image/jpeg', 'name' => null],
        ],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[0]->content)->toBe('Describe this image.')
        ->and($messages[0]->attachments)->toHaveCount(1)
        ->and($messages[0]->attachments->first())->toBeInstanceOf(RemoteImage::class)
        ->and($messages[0]->attachments->first()->url)->toBe('https://example.com/photo.jpg');
});

test('user messages with multiple attachment types are all rehydrated', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Multi-attachment conversation');

    ConversationSchema::saveMessage([
        'id' => ConversationFixtures::MESSAGE_ONE,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => ConversationFixtures::PARTICIPANT,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Analyze these files.',
        'attachments' => [
            ['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg', 'mime' => 'image/jpeg', 'name' => null],
            ['type' => 'stored-document', 'path' => 'docs/report.pdf', 'filesystem' => 'local', 'name' => 'report.pdf'],
        ],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[0]->attachments)->toHaveCount(2)
        ->and($messages[0]->attachments->toList()[0])->toBeInstanceOf(RemoteImage::class)
        ->and($messages[0]->attachments->toList()[1])->toBeInstanceOf(StoredDocument::class)
        ->and($messages[0]->attachments->toList()[1]->path)->toBe('docs/report.pdf');
});

test('user messages with no attachments are returned as plain Message', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Plain conversation');

    ConversationSchema::saveMessage([
        'id' => ConversationFixtures::MESSAGE_ONE,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => ConversationFixtures::PARTICIPANT,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Hello.',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages[0])->toBeInstanceOf(Message::class)
        ->and($messages[0])->not->toBeInstanceOf(UserMessage::class);
});

test('malformed stored attachment JSON fails loudly', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Malformed attachment conversation');

    ConversationSchema::saveMessage([
        'id' => ConversationFixtures::MESSAGE_ONE,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => ConversationFixtures::PARTICIPANT,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Describe this image.',
        'attachments' => ['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg'],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    expect(fn(): Collection => $store->getLatestConversationMessages($conversationId, 10))
        ->toThrow(InvalidArgumentException::class, 'Stored conversation attachments must be a JSON array.');
});

test('malformed known stored attachments fail loudly', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Malformed attachment conversation');

    ConversationSchema::saveMessage([
        'id' => ConversationFixtures::MESSAGE_ONE,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => ConversationFixtures::PARTICIPANT,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Describe this image.',
        'attachments' => [
            ['type' => 'remote-image', 'mime' => 'image/jpeg'],
        ],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    expect(fn(): Collection => $store->getLatestConversationMessages($conversationId, 10))
        ->toThrow(InvalidArgumentException::class, 'Cannot reconstruct [remote-image] attachment because [url] is missing or invalid.');
});

test('it replays a resumed approval so the paused tool_use is answered', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']]]),
    ], ['call-1' => null]);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]);

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_TWO, 'Let me delete b too', [
        assistantStep([['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]]),
    ], ['call-2' => null]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls->first()->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults)->toHaveCount(1)
        ->and($messages[1]->toolResults->first()->result)->toBe('Deleted x')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Let me delete b too')
        ->and($messages[2]->toolCalls)->toHaveCount(1)
        ->and($messages[2]->toolCalls->first()->id)->toBe('call-2');
});

test('storing approval results for a conversation with no paused row throws', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    expect(fn() => $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]))->toThrow(ApprovalMismatchException::class, 'The approval results do not match a paused conversation turn.');
});

test('a mismatch against a paused row carries the approvals that are actually pending', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', '', [assistantStep(
        [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']],
            ['id' => 'call-2', 'name' => 'read_file', 'arguments' => ['path' => 'y']],
        ],
    )], ['call-1' => 'Destructive operation.']);

    try {
        $store->storeApprovalResults($conversationId, [
            new ToolResult('call-9', 'delete_file', ['path' => 'x'], 'Deleted x'),
        ]);

        $this->fail('Expected an approval mismatch.');
    } catch (ApprovalMismatchException $approvalMismatchException) {
        expect(array_map(fn($approval): array => $approval->toArray(), $approvalMismatchException->pendingApprovals->toList()))->toBe([
            ['id' => 'call-1', 'tool' => 'delete_file', 'arguments' => ['path' => 'x'], 'reason' => 'Destructive operation.'],
        ]);
    }
});

test('resolving approval results does not require the resolver to be the paused turn participant', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']]]),
    ], ['call-1' => 'Deletes x']);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $messagesTable->updateAll(
        ['participant_id' => ConversationFixtures::OTHER_PARTICIPANT],
        ['id' => ConversationFixtures::MESSAGE_ONE],
    );

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]);

    $record = $messagesTable->get(ConversationFixtures::MESSAGE_ONE);

    expect($store->pendingApprovalsFor($conversationId))->toBe([])
        ->and($record->steps[0]['tool_calls'][0])->toMatchArray(['id' => 'call-1', 'result' => 'Deleted x']);
});

test('resolving approval results writes each outcome into the step that made the call', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep(
            [['id' => 'call-0', 'name' => 'read_file', 'arguments' => ['path' => 'a']]],
            [['id' => 'call-0', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
        ),
        assistantStep([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']],
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'y']],
        ]),
    ], ['call-1' => 'Deletes x', 'call-2' => 'Deletes y'], [], null);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $partial = $store->pendingApprovalsFor($conversationId);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
        new ToolResult('call-2', 'delete_file', ['path' => 'y'], 'The user rejected this tool call.', denied: true),
    ]);

    $record = $messagesTable->get(ConversationFixtures::MESSAGE_ONE);
    $steps = $record->steps;

    expect($partial)->toHaveCount(1)
        ->and($partial[0])->toMatchObject(['id' => 'call-2', 'reason' => 'Deletes y'])
        ->and($store->pendingApprovalsFor($conversationId))->toBe([])
        ->and($steps)->toHaveCount(2)
        ->and($steps[0]['tool_calls'])->toHaveCount(1)
        ->and($steps[0]['tool_calls'][0]['id'])->toBe('call-0')
        ->and($steps[1]['tool_calls'])->toHaveCount(2)
        ->and($steps[1]['tool_calls'][0])->toMatchArray(['id' => 'call-1'])
        ->and($steps[1]['tool_calls'][0])->not->toHaveKey('denied')
        ->and($steps[1]['tool_calls'][1])->toMatchArray(['id' => 'call-2', 'denied' => true]);
});

test('resolving an edited approval records the arguments the tool actually ran with', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']]]),
    ], ['call-1' => 'Deletes x'], [], null);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'y'], 'Deleted y'),
    ]);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->get(ConversationFixtures::MESSAGE_ONE);

    expect($record->steps[0]['tool_calls'])->toHaveCount(1)
        ->and($record->steps[0]['tool_calls'][0])->toMatchArray([
            'id' => 'call-1',
            'arguments' => ['path' => 'y'],
            'result' => 'Deleted y',
        ]);
});

test('it keeps an executed call and a pending call together on a mixed pause step', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, 'Let me delete b too', [
        assistantStep(
            [
                ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a']],
                ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']],
            ],
            [
                ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a'],
            ],
        ),
    ], ['call-2' => null]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('Let me delete b too')
        ->and($messages[0]->toolCalls->map(fn($call): string => $call->id)->toList())->toBe(['call-1', 'call-2'])
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults)->toHaveCount(1)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1');
});

test('completing a turn stores no replay blocks and drops those of the paused rows it resumed', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result' => 'deleted']],
            [],
            [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        ),
    ], [], ['provider' => 'anthropic']);

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        '',
        [],
        Double::for(TextProvider::class),
        'test-model',
        approvalDecisions: Decisions::from(['call-1' => true]),
    );

    $response = (new AgentResponse('invocation-id', 'Deleted b.', new TextUsage(), new Meta('anthropic')))
        ->withSteps(collection([
            new Step('Deleted b.', [], [], FinishReason::Stop, new TextUsage(), new Meta(), '', [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'text', 'text' => 'Deleted b.']]),
        ]));

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $blocks = collection($messagesTable->find()->where(['role' => 'assistant'])->all()->toList())
        ->unfold(fn($record): array => collection((array)$record->steps)->map(fn($step): array => $step['replay_blocks'])->toList())
        ->toList();

    expect($blocks)->toBe([[], []])
        ->and($messagesTable->get(ConversationFixtures::MESSAGE_ONE)->status)->toBe(MessageStatus::Completed);
});

test('a turn that pauses again keeps the replay blocks of the rows it resumed', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result' => 'deleted']],
            [],
            [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        ),
    ], [], ['provider' => 'anthropic']);

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        '',
        [],
        Double::for(TextProvider::class),
        'test-model',
        approvalDecisions: Decisions::from(['call-1' => true]),
    );

    $response = (new AgentResponse('invocation-id', '', new TextUsage(), new Meta('anthropic')))
        ->withSteps(collection([
            new Step('', [new ToolCall('call-2', 'DeleteFile', ['path' => 'c'])], [], FinishReason::ToolCalls, new TextUsage(), new Meta(), '', [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']]),
        ]))
        ->withPendingApprovals(collection([new PendingApproval('call-2', 'DeleteFile', ['path' => 'c'], 'Deletes a file')]));

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $blocks = collection($messagesTable->find()->where(['role' => 'assistant'])->all()->toList())
        ->unfold(fn($record): array => collection((array)$record->steps)->map(fn($step): array => $step['replay_blocks'])->toList())
        ->toList();

    expect($blocks)->toEqualCanonicalizing([
        [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
    ])->and($messagesTable->get(ConversationFixtures::MESSAGE_ONE)->status)->toBe(MessageStatus::Paused);
});

test('it skips a step that has nothing left to say once its unexecuted calls are dropped', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]]],
            [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']]],
        ),
        assistantStep([['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1]]]),
    ], meta: ['provider' => 'anthropic']);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class);
});

test('it replays a multi-step pause with each step carrying its own replay blocks', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, 'Let me delete b too', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a']]],
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
            [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
        ),
        assistantStep(
            [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]],
            [],
            [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
        ),
    ], ['call-2' => null], ['provider' => 'anthropic']);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->replayBlocks)->toBe([['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']])
        ->and($messages[0]->replayBlocksProvider)->toBe('anthropic')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults)->toHaveCount(1)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Let me delete b too')
        ->and($messages[2]->replayBlocks)->toBe([['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']])
        ->and($messages[2]->replayBlocksProvider)->toBe('anthropic')
        ->and($messages[2]->toolCalls)->toHaveCount(1)
        ->and($messages[2]->toolCalls->first()->id)->toBe('call-2');
});

test('it merges a re-paused turn text into the new tool_use message rather than emitting two assistant messages', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a']]]),
    ], ['call-1' => null]);

    $store->storeApprovalResults($conversationId, [
        new ToolResult('call-1', 'delete_file', ['path' => 'a'], 'Deleted a'),
    ]);

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_TWO, 'Let me delete that file', [
        assistantStep([['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]]),
    ], ['call-2' => null]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls->first()->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Let me delete that file')
        ->and($messages[2]->toolCalls->first()->id)->toBe('call-2');
});

test('it writes the steps of a paused turn with their replay blocks and keeps replay state out of meta', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Delete config/app.php.',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = (new AgentResponse('invocation-id', 'Let me think about that', new TextUsage(), new Meta('anthropic')))
        ->withSteps(collection([
            new Step('', [new ToolCall('call-0', 'ReadFile', ['path' => 'a'])], [new ToolResult('call-0', 'ReadFile', ['path' => 'a'], 'contents')], FinishReason::ToolCalls, new TextUsage(), new Meta(), '', [['type' => 'tool_use', 'id' => 'call-0']]),
            new Step('Let me think about that', [new ToolCall('call-1', 'DeleteFile', ['path' => 'config/app.php'])], [], FinishReason::ToolCalls, new TextUsage(), new Meta(), '', [['type' => 'thinking', 'signature' => 'sig-1']]),
        ]))
        ->withPendingApprovals(collection([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
        ]));

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    $steps = $record->steps;

    expect($steps)->toHaveCount(2)
        ->and($steps[0]['content'])->toBe('')
        ->and($steps[0]['replay_blocks'])->toMatchArray([['type' => 'tool_use', 'id' => 'call-0']])
        ->and($steps[0]['tool_calls'])->toHaveCount(1)
        ->and($steps[0]['tool_calls'][0]['id'])->toBe('call-0')
        ->and($steps[0]['tool_calls'][0]['result'])->toBe('contents')
        ->and($steps[1]['content'])->toBe('Let me think about that')
        ->and($steps[1]['replay_blocks'])->toMatchArray([['type' => 'thinking', 'signature' => 'sig-1']])
        ->and($steps[1]['tool_calls'])->toHaveCount(1)
        ->and($steps[1]['tool_calls'][0]['id'])->toBe('call-1')
        ->and(array_key_exists('result', $steps[1]['tool_calls'][0]))->toBeFalse()
        ->and($record->meta)->toMatchArray(['provider' => 'anthropic', 'model' => null, 'citations' => []])
        ->and($steps[1]['tool_calls'][0]['approval_reason'])->toBe('Deletes a file')
        ->and($record->status)->toBe(MessageStatus::Paused);
});

test('a bare rejection resume does not persist a blank assistant row', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Approval conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]],
            [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => [], 'result' => 'The user rejected this tool call.', 'result_id' => null]],
        ),
    ], []);

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        '',
        [],
        Double::for(TextProvider::class),
        'test-model',
        approvalDecisions: Decisions::from(['call-1' => Decision::reject()]),
    );

    $response = new AgentResponse('invocation-id', '', new TextUsage(), new Meta());
    $response->toolResults = collection([
        new ToolResult('call-1', 'DeleteFile', [], 'The user rejected this tool call.'),
    ]);

    $messageId = $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    expect($messageId)->toBe(ConversationFixtures::MESSAGE_ONE);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    expect($messagesTable->find()->where(['role' => 'assistant'])->count())->toBe(1);
});

test('a resume folds its steps, text and usage into the paused row', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Approval conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]],
            [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => [], 'result' => 'Deleted', 'result_id' => null]],
        ),
    ], []);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $paused = $messagesTable->get(ConversationFixtures::MESSAGE_ONE);
    $paused->usage_data = ['input_tokens' => 10, 'output_tokens' => 5];

    $messagesTable->saveOrFail($paused);

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        '',
        [],
        Double::for(TextProvider::class),
        'test-model',
        approvalDecisions: Decisions::from(['call-1' => true]),
    );

    $response = new AgentResponse('invocation-id', 'Done.', new TextUsage(20, 7), new Meta('openai', 'gpt-5'));
    $response->steps = collection([new Step('Done.', [], [], FinishReason::Stop, new TextUsage(20, 7), new Meta(), '', [])]);

    $messageId = $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    $row = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($messageId)->toBe(ConversationFixtures::MESSAGE_ONE)
        ->and($row->content)->toBe('Done.')
        ->and($row->steps)->toHaveCount(2)
        ->and($row->steps[1]['content'])->toBe('Done.')
        ->and($row->usage_data)->toMatchArray(['input_tokens' => 30, 'output_tokens' => 12])
        ->and($row->meta)->toMatchArray(['provider' => 'openai', 'model' => 'gpt-5']);
});

test('a resume keeps the citations the paused half of the turn collected', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Approval conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep([['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]]),
    ], ['call-1' => 'Deletes a file'], ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'citations' => [(new UrlCitation('https://cakephp.org/docs/ai'))->toArray()]]);

    $prompt = new AgentPrompt(new ToolUsingAgent(), '', [], Double::for(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

    $meta = new Meta('anthropic', 'claude-sonnet-4-6', [new UrlCitation('https://cakephp.org/docs/mcp')]);

    $response = (new AgentResponse('invocation-id', 'Deleted.', new TextUsage(), $meta))->withSteps(collection([
        new Step('Deleted.', [], [], FinishReason::Stop, new TextUsage(), $meta, '', []),
    ]));

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $citations = $messagesTable->get(ConversationFixtures::MESSAGE_ONE)->meta['citations'] ?? [];

    expect($messagesTable->get(ConversationFixtures::MESSAGE_ONE)->meta['model'])->toBe('claude-sonnet-4-6')
        ->and($citations)->toHaveCount(2)
        ->and($citations[0]['url'])->toBe('https://cakephp.org/docs/ai')
        ->and($citations[1]['url'])->toBe('https://cakephp.org/docs/mcp');
});

test('a fold that recorded no result does not leave the row reporting a pending approval', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Approval conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, 'Waiting.', [
        assistantStep([['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]]),
    ], ['call-1' => 'Deletes a file']);

    $prompt = new AgentPrompt(new ToolUsingAgent(), '', [], Double::for(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

    $response = (new AgentResponse('invocation-id', 'Done.', new TextUsage(), new Meta()))->withSteps(collection([
        new Step('Done.', [], [], FinishReason::Stop, new TextUsage(), new Meta(), '', []),
    ]));

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    expect($store->pendingApprovalsFor($conversationId))->toBe([]);
});

test('a resume does not fold into a settled row once a newer plain turn follows it', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Approval conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, 'Old.', [assistantStep()], []);
    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_TWO, 'Hi.', [assistantStep()]);

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        '',
        [],
        Double::for(TextProvider::class),
        'test-model',
        approvalDecisions: Decisions::from(['call-1' => true]),
    );

    $response = new AgentResponse('invocation-id', 'Done.', new TextUsage(), new Meta());
    $response->steps = collection([new Step('Done.', [], [], FinishReason::Stop, new TextUsage(), new Meta(), '', [])]);

    $messageId = $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');

    expect($messageId)->not->toBe(ConversationFixtures::MESSAGE_ONE)
        ->and($messageId)->not->toBe(ConversationFixtures::MESSAGE_TWO)
        ->and($messagesTable->get(ConversationFixtures::MESSAGE_ONE)->content)->toBe('Old.');
});

test('a resume folds into the paused row holding its decided call rather than the newest pause', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Approval conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, '', [
        assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'deleted']]),
    ], ['call-1' => 'Deletes a file'], ['provider' => 'anthropic']);

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_TWO, '', [
        assistantStep([['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]]),
    ], ['call-2' => 'Deletes b file'], ['provider' => 'anthropic']);

    $prompt = new AgentPrompt(new ToolUsingAgent(), '', [], Double::for(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

    $response = (new AgentResponse('invocation-id', 'Deleted a.', new TextUsage(), new Meta('anthropic')))->withSteps(collection([
        new Step('Deleted a.', [], [], FinishReason::Stop, new TextUsage(), new Meta(), '', []),
    ]));

    expect($store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response))->toBe(ConversationFixtures::MESSAGE_ONE);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $rows = [
        ConversationFixtures::MESSAGE_ONE => $messagesTable->get(ConversationFixtures::MESSAGE_ONE),
        ConversationFixtures::MESSAGE_TWO => $messagesTable->get(ConversationFixtures::MESSAGE_TWO),
    ];

    expect($rows[ConversationFixtures::MESSAGE_ONE]->content)->toBe('Deleted a.')
        ->and($rows[ConversationFixtures::MESSAGE_ONE]->status)->toBe(MessageStatus::Completed)
        ->and($rows[ConversationFixtures::MESSAGE_TWO]->content)->toBe('')
        ->and($rows[ConversationFixtures::MESSAGE_TWO]->status)->toBe(MessageStatus::Paused);
});

test('it omits provider content blocks when the assistant turn is not paused', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Delete config/app.php.',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = (new AgentResponse('invocation-id', 'Deleted the file.', new TextUsage(), new Meta()))
        ->withMessages(collection([
            new AssistantMessage('Deleted the file.', null, [['type' => 'thinking', 'signature' => 'sig-1']]),
        ]));

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->meta)->not->toHaveKey('provider_steps')
        ->and($record->steps)->toHaveCount(1)
        ->and($record->steps[0]['replay_blocks'])->toBe([]);
});

test('it writes the steps a paused stream carried on its approval request', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Delete config/app.php.',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collection([
        new ToolApprovalRequest('event-1', collection([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
        ]), 0, collection([
            new Step('', [new ToolCall('call-1', 'DeleteFile', ['path' => 'config/app.php'])], [], FinishReason::ToolCalls, new TextUsage(), new Meta(), '', [['type' => 'thinking', 'signature' => 'sig-1']]),
        ])),
    ]), new Meta());

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    $steps = $record->steps;

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['replay_blocks'])->toBe([['type' => 'thinking', 'signature' => 'sig-1']])
        ->and($steps[0]['tool_calls'])->toHaveCount(1)
        ->and($steps[0]['tool_calls'][0]['id'])->toBe('call-1')
        ->and(array_key_exists('result', $steps[0]['tool_calls'][0]))->toBeFalse();
});

test('it writes the steps a completed stream carried on its stream end', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Read config/app.php.',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $call = new ToolCall('call-1', 'ReadFile', ['path' => 'config/app.php']);

    $response = new StreamedAgentResponse('invocation-id', collection([
        new TextDelta(uniqid(), ConversationFixtures::MESSAGE_ONE, 'Done.', time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time(), collection([
            new Step('', [$call], [new ToolResult('call-1', 'ReadFile', ['path' => 'config/app.php'], 'contents')], FinishReason::ToolCalls, new TextUsage(), new Meta(), '', [['type' => 'tool_use', 'id' => 'call-1']]),
            new Step('Done.', [], [], FinishReason::Stop, new TextUsage(), new Meta(), '', [['type' => 'text', 'text' => 'Done.']]),
        ])),
    ]), new Meta());

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    $steps = $record->steps;

    expect($steps)->toHaveCount(2)
        ->and($steps[0]['replay_blocks'])->toBe([])
        ->and($steps[0]['tool_calls'])->toHaveCount(1)
        ->and($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'call-1', 'result' => 'contents'])
        ->and($steps[1])->toMatchArray(['tool_calls' => [], 'replay_blocks' => []]);
});

test('it records the reasoning a streamed turn produced onto the turn steps', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Reasoning conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'How cold is it?',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collection([
        new ReasoningStart(uniqid(), 'reasoning-1', time()),
        new ReasoningDelta(uniqid(), 'reasoning-1', 'They want ', time()),
        new ReasoningDelta(uniqid(), 'reasoning-1', 'the temperature.', time()),
        new ReasoningEnd(uniqid(), 'reasoning-1', time()),
        new TextDelta(uniqid(), ConversationFixtures::MESSAGE_ONE, 'It is 12°C.', time()),
    ]), new Meta());

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->steps)->toHaveCount(1)
        ->and($record->steps[0])->toMatchArray(['content' => 'It is 12°C.', 'reasoning' => 'They want the temperature.'])
        ->and($record->meta)->not->toHaveKey('reasoning');
});

test('it records the reasoning a prompted turn produced onto the turn steps', function (): void {
    Configure::write('Ai.conversations.generate_title', false);
    Configure::write('Ai.providers.deepseek', [

        ...(array)Configure::read('Ai.providers.deepseek'),
        'key' => 'test-key',
    ]);

    aiHttpFake(['api.deepseek.com/*' => aiHttpResponse([
        'id' => 'chatcmpl-reasoner-1',
        'object' => 'chat.completion',
        'model' => 'deepseek-reasoner',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'reasoning_content' => 'They want the temperature.',
                'content' => 'It is 12°C.',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $response = (new RememberingAssistantAgent())
        ->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])
        ->prompt('How cold is it?', provider: 'deepseek', model: 'deepseek-reasoner');

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['conversation_id' => $response->conversationId, 'role' => 'assistant'])->firstOrFail();

    expect($record->steps)->toHaveCount(1)
        ->and($record->steps[0])->toHaveKey('reasoning', 'They want the temperature.')
        ->and($record->meta)->not->toHaveKey('reasoning');
});

test('it records no reasoning on the turn steps when the model did not reason', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Quiet conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'How cold is it?',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collection([
        new TextDelta(uniqid(), ConversationFixtures::MESSAGE_ONE, 'It is 12°C.', time()),
    ]), new Meta());

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->steps[0])->toHaveKey('reasoning', '');
});

test('it records the sources a streamed turn cited into the message meta', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Researched conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'What does Crustum MCP do?',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collection([
        new TextDelta(uniqid(), ConversationFixtures::MESSAGE_ONE, 'Crustum MCP ships an MCP server.', time()),
        new CitationEvent(uniqid(), ConversationFixtures::MESSAGE_ONE, new UrlCitation('https://modelcontextprotocol.io', 'MCP Documentation'), time()),
        new CitationEvent(uniqid(), ConversationFixtures::MESSAGE_ONE, new UrlCitation('https://modelcontextprotocol.io', 'MCP Documentation'), time()),
    ]), new Meta());

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->meta['citations'])->toEqual([
        ['url' => 'https://modelcontextprotocol.io', 'title' => 'MCP Documentation', 'start_index' => null, 'end_index' => null],
        ['url' => 'https://modelcontextprotocol.io', 'title' => 'MCP Documentation', 'start_index' => null, 'end_index' => null],
    ]);
});

test('it stores no sources when a streamed turn cited nothing', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Unresearched conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'How cold is it?',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collection([
        new TextDelta(uniqid(), ConversationFixtures::MESSAGE_ONE, 'It is 12°C.', time()),
    ]), new Meta());

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->meta['citations'])->toBe([]);
});

test('it stores a user message from an agent class and a user message', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Prompt conversation');

    $messageId = $store->storeUserMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, ToolUsingAgent::class, new UserMessage('Check my order status.'));

    $record = ConversationTable::first('agent_conversation_messages', ['id' => $messageId]);

    expect($record->conversation_id)->toBe($conversationId)
        ->and($record->agent)->toBe(ToolUsingAgent::class)
        ->and($record->role)->toBe('user')
        ->and($record->content)->toBe('Check my order status.')
        ->and($record->attachments)->toBe('[]');
});

test('it stores the attachments a user message carries', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Prompt conversation');

    $messageId = $store->storeUserMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, ToolUsingAgent::class, new UserMessage(
        'What is in this?',
        [new RemoteImage('https://example.com/order.png')],
    ));

    $record = ConversationTable::first('agent_conversation_messages', ['id' => $messageId]);
    $attachments = json_decode((string)$record->attachments, true);

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]['url'])->toBe('https://example.com/order.png');
});

test('it touches the conversation when a user message is stored', function (): void {
    DateTime::withTestNow('2026-01-01 00:00:00', function (): void {
        $store = new DatabaseConversationStore();
        $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Prompt conversation');

        ConnectionManager::get('test')->update(
            'agent_conversations',
            ['modified' => DateTime::now()->subDays(1)],
            ['id' => $conversationId],
            ['modified' => 'datetime'],
        );

        $store->storeUserMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, ToolUsingAgent::class, new UserMessage('Check my order status.'));

        expect((string)ConversationTable::first('agent_conversations', ['id' => $conversationId])->modified)
            ->toBe(DateTime::now()->toDateTimeString());
    });
});

test('provider reasoning state is kept on the step replay blocks rather than copied onto each tool call', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Reasoning conversation');
    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Look it up',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $reasoningItem = ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'];

    $response = (new AgentResponse('invocation-1', 'Found it.', new TextUsage(), new Meta('openai', 'gpt-5')))
        ->withSteps(collection([new Step(
            'Found it.',
            [new ToolCall('fc_1', 'ReadFile', ['path' => 'a'], 'call_1', 'rs_1', [], 'enc-blob-1')],
            [new ToolResult('fc_1', 'ReadFile', ['path' => 'a'], 'contents', 'call_1')],
            FinishReason::Stop,
            new TextUsage(),
            new Meta('openai', 'gpt-5'),
            '',
            [$reasoningItem, ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'ReadFile', 'arguments' => '{"path":"a"}']],
        )]));

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $step = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail()->steps[0];

    expect($step['tool_calls'][0])->toBe([
        'id' => 'fc_1',
        'name' => 'ReadFile',
        'arguments' => ['path' => 'a'],
        'result_id' => 'call_1',
        'result' => 'contents',
    ])->and($step['replay_blocks'])->toBe([]);
});

test('a step that dropped an unanswered call replays generically so no raw block names a call without a result', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Tool conversation');

    insertAssistantTurn($conversationId, ConversationFixtures::MESSAGE_ONE, 'Read a', [
        assistantStep(
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a']], ['id' => 'call-2', 'name' => 'read_file', 'arguments' => ['path' => 'b']]],
            [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
            [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1'], ['type' => 'tool_use', 'id' => 'call-2']],
        ),
    ], meta: ['provider' => 'anthropic']);

    $message = $store->getLatestConversationMessages($conversationId, 10)->toList()[0];

    expect($message)->toBeInstanceOf(AssistantMessage::class)
        ->and($message->replayBlocks)->toBe([])
        ->and($message->toolCalls->map(fn($call): string => $call->id)->toList())->toBe(['call-1']);
});

test('provider tool calls are stored per step and exposed on the stored message and model', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', ConversationFixtures::PARTICIPANT, 'Search conversation');
    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Search',
        [],
        Double::for(TextProvider::class),
        'test-model',
    );

    $search = new ProviderToolCall('ws-1', 'web_search_call', ['action' => ['query' => 'cakephp ai']]);
    $execution = new ProviderToolCall('ce-1', 'code_interpreter_call', ['code' => 'print(1)']);

    $response = (new AgentResponse('invocation-1', 'Found it.', new TextUsage(), new Meta('openai', 'gpt-5')))
        ->withSteps(collection([
            new Step('', [], [], FinishReason::Stop, new TextUsage(), new Meta('openai', 'gpt-5'), '', [], [$search]),
            new Step('Found it.', [], [], FinishReason::Stop, new TextUsage(), new Meta('openai', 'gpt-5'), '', [], [$execution]),
        ]));

    $store->storeAssistantMessage($conversationId, 'user', ConversationFixtures::PARTICIPANT, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $steps = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail()->steps;

    expect($steps[0]['provider_tool_calls'])->toBe([$search->toArray()])
        ->and($steps[1]['provider_tool_calls'])->toBe([$execution->toArray()])
        ->and($store->paginateConversationMessages($conversationId, 1)->items()[0]->providerToolCalls())->toBe([$search->toArray(), $execution->toArray()])
        ->and($messagesTable->find()->where(['role' => 'assistant'])->firstOrFail()->provider_tool_calls)->toBe([$search->toArray(), $execution->toArray()]);
});

/**
 * @return array<string, mixed>
 */
function storedConversationMessageAttributes(string $id, string $conversationId, string $content, ?string $participantType = 'user', ?string $participantId = null): array
{
    return [
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => $participantType,
        'participant_id' => $participantId ?? ConversationFixtures::PARTICIPANT,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => $content,
        'attachments' => [],
        'steps' => [],
        'usage_data' => [],
        'meta' => [],
        'status' => MessageStatus::Completed,
        'created' => now(),
        'modified' => now(),
    ];
}

/**
 * Build a single stored step, one model round-trip of a turn.
 *
 * @param list<array<string, mixed>> $toolCalls
 * @param list<array<string, mixed>> $toolResults
 * @param list<array<string, mixed>> $replayBlocks
 * @return array{content: string, tool_calls: list<array<string, mixed>>, replay_blocks: list<array<string, mixed>>}
 */
function assistantStep(array $toolCalls = [], array $toolResults = [], array $replayBlocks = [], string $content = ''): array
{
    $results = [];

    foreach ($toolResults as $result) {
        $results[$result['id']] = $result;
    }

    return [
        'content' => $content,
        'tool_calls' => array_map(
            fn(array $call): array => array_merge(
                $call,
                array_intersect_key($results[$call['id']] ?? [], ['result' => true, 'denied' => true, 'failed' => true]),
            ),
            $toolCalls,
        ),
        'replay_blocks' => $replayBlocks,
    ];
}

/**
 * Insert an assistant turn built from explicit steps.
 *
 * @param list<array<string, mixed>> $steps
 * @param array<string, string|null>|null $pending Reasons keyed by the tool call IDs still awaiting a decision, or null when the turn never paused
 * @param array<string, mixed> $meta
 */
function insertAssistantTurn(
    string $conversationId,
    string $id,
    string $content,
    array $steps,
    ?array $pending = null,
    array $meta = [],
    ?string $participantType = 'user',
): void {
    if ($steps !== [] && ($steps[array_key_last($steps)]['content'] ?? '') === '') {
        $steps[array_key_last($steps)]['content'] = $content;
    }

    $steps = array_map(fn(array $step): array => [...$step, 'tool_calls' => array_map(
        fn(array $toolCall): array => array_key_exists($toolCall['id'] ?? '', $pending ?? []) ? [...$toolCall, 'approval_reason' => $pending[$toolCall['id']]] : $toolCall,
        (array)($step['tool_calls'] ?? []),
    )], $steps);

    ConversationSchema::saveMessage([
        ...storedConversationMessageAttributes($id, $conversationId, $content, $participantType),
        'role' => 'assistant',
        'steps' => $steps,
        'meta' => $meta,
        'status' => $pending === null ? MessageStatus::Completed : MessageStatus::Paused,
    ]);
}

/**
 * @param list<string> $ids
 */
function insertStoredConversationMessages(string $conversationId, array $ids): void
{
    foreach ($ids as $id) {
        ConversationSchema::saveMessage(
            storedConversationMessageAttributes($id, $conversationId, "Content for {$id}"),
        );
    }
}

/**
 * @param list<array<string, mixed>> $toolCalls
 * @param array<string, string|null> $pending
 * @param list<array<string, mixed>> $toolResults
 */
function insertPausedConversationTurn(
    string $conversationId,
    string $id,
    array $toolCalls,
    array $pending,
    array $toolResults = [],
): void {
    insertAssistantTurn($conversationId, $id, 'Waiting on you.', [assistantStep($toolCalls, $toolResults)], $pending);
}
