<?php
declare(strict_types=1);

use Cake\Collection\Collection;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\StoredDocument;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Test\Fixtures\Agents\RememberingToolUsingAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Database\ConversationSchema;
use Crustum\Ai\Test\Support\Database\ConversationTable;
use TestApp\Model\Entity\User;

beforeEach(function (): void {
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');
});

test('it writes conversations to the default tables', function (): void {
    $store = new DatabaseConversationStore();

    $conversationId = $store->storeConversation(null, '1', 'Hello');

    expect(ConversationTable::exists('agent_conversations', ['id' => $conversationId, 'title' => 'Hello']))->toBeTrue();
});

test('it finds the latest conversation by participant type and id', function (): void {
    $store = new DatabaseConversationStore();
    $type = User::class;

    $older = $store->storeConversation($type, '7', 'Older');
    $newer = $store->storeConversation($type, '7', 'Newer');
    $store->storeConversation($type, '8', 'Other');
    $store->storeConversation('Other\\Type', '7', 'Wrong type');

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = $this->getTableLocator()->get('Crustum/Ai.Conversations');
    $conversationsTable->updateAll(['modified' => now()->subMinutes(5)], ['id' => $older]);
    $conversationsTable->updateAll(['modified' => now()], ['id' => $newer]);

    expect($store->latestConversationId($type, '7'))->toBe($newer)
        ->and($store->latestConversationId($type, 8))->not->toBe($older)
        ->and($store->latestConversationId($type, '8'))->not->toBe($newer);
});

test('it writes to overridden table names from config', function (): void {
    Configure::write('Ai.conversations.tables.conversations', 'custom_conversations');
    Configure::write('Ai.conversations.tables.messages', 'custom_conversation_messages');

    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Hello');

    expect(ConversationTable::exists('custom_conversations', ['id' => $conversationId]))->toBeTrue()
        ->and(aiDbExists('agent_conversations', ['id' => $conversationId]))->toBeFalse();
});

test('it routes queries through the configured connection', function (): void {
    ConversationSchema::create('secondary');

    $store = new DatabaseConversationStore('secondary');
    $conversationId = $store->storeConversation(null, '1', 'Hello');

    expect(ConversationTable::exists('agent_conversations', ['id' => $conversationId], 'secondary'))->toBeTrue()
        ->and(aiDbExists('agent_conversations', ['id' => $conversationId], 'test'))->toBeFalse();
})->after(function (): void {
    Configure::write('Ai.conversations.connection', 'test');
    ConnectionManager::get('secondary')->execute('DELETE FROM agent_conversation_messages');
    ConnectionManager::get('secondary')->execute('DELETE FROM agent_conversations');
});

test('it persists tool calls and results from a remembered agent prompt', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            aiHttpResponse([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'id' => 'call_123',
                                'name' => 'FixedNumberGenerator',
                                'args' => (object)[],
                            ],
                        ]],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'gemini-3.5-flash',
            ]),
            aiHttpResponse([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'The number is 72019']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'gemini-3.5-flash',
            ]),
        ]),
    ]);

    Configure::write('Ai.providers.gemini.key', 'test-key');

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', $user->id, 'Tool conversation');

    (new RememberingToolUsingAgent())
        ->continue($conversationId, $user)
        ->prompt('Generate a random number', provider: 'gemini');

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect(array_is_list($record->tool_calls))->toBeTrue()
        ->and(array_is_list($record->tool_results))->toBeTrue();
});

test('it stores sparse keyed tool calls and results as JSON arrays', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Check my order status.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = new AgentResponse('invocation-id', 'The order has shipped.', new Usage(), new Meta());
    $response->toolCalls = new Collection([
        2 => new ToolCall('call-1', 'lookup_order', ['id' => 1]),
        8 => new ToolCall('call-2', 'lookup_carrier', ['id' => 1]),
    ]);
    $response->toolResults = new Collection([
        2 => new ToolResult('call-1', 'lookup_order', ['id' => 1], ['status' => 'shipped']),
        8 => new ToolResult('call-2', 'lookup_carrier', ['id' => 1], ['carrier' => 'UPS']),
    ]);

    $store->storeAssistantMessage($conversationId, null, '1', $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect(array_is_list($record->tool_calls))->toBeTrue()
        ->and(array_is_list($record->tool_results))->toBeTrue();
});

test('it reloads legacy sparse keyed tool calls and results as lists', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'The order has shipped.',
        'attachments' => [],
        'tool_calls' => [
            2 => ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]],
            8 => ['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1]],
        ],
        'tool_results' => [
            2 => ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']],
            8 => ['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1], 'result' => ['carrier' => 'UPS']],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and(array_keys($messages[0]->toolCalls->toArray()))->toBe([0, 1])
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and(array_keys($messages[1]->toolResults->toArray()))->toBe([0, 1])
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('The order has shipped.');
});

test('it replays stored tool conversations before the final assistant response', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'The order has shipped.',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result_id' => 'result-1'],
        ],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped'], 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('')
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls->first()->resultId)->toBe('result-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults)->toHaveCount(1)
        ->and($messages[1]->toolResults->first()->resultId)->toBe('result-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('The order has shipped.')
        ->and($messages[2]->toolCalls->isEmpty())->toBeTrue();
});

test('it drops unresolved tool calls on an unmarked legacy row that only some results answered', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'The order has shipped.',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result_id' => 'result-1'],
            ['id' => 'call-2', 'name' => 'lookup_customer', 'arguments' => ['id' => 2], 'result_id' => 'result-2'],
        ],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped'], 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls->first()->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults)->toHaveCount(1)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('The order has shipped.');
});

test('it replays a duplicated tool result only once against the call it answers', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'The order has shipped.',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result_id' => 'result-1'],
        ],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped'], 'result_id' => 'result-1'],
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped'], 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults)->toHaveCount(1)
        ->and($messages[2]->content)->toBe('The order has shipped.');
});

test('it drops tool calls when the provider omitted the tool call ids', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'The order has shipped.',
        'attachments' => [],
        'tool_calls' => [
            ['id' => '', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result_id' => 'result-1'],
            ['id' => '', 'name' => 'lookup_customer', 'arguments' => ['id' => 2], 'result_id' => 'result-2'],
        ],
        'tool_results' => [
            ['id' => '', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped'], 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls->isEmpty())->toBeTrue()
        ->and($messages[0]->content)->toBe('The order has shipped.');
});

test('it drops resultless tool calls and replays only the final assistant text', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
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
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
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

test('it rehydrates reasoning encrypted content on stored tool calls', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Reasoning conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Looking that up.',
        'attachments' => [],
        'tool_calls' => [
            [
                'id' => 'call-1',
                'name' => 'lookup_order',
                'arguments' => ['id' => 1],
                'reasoning_id' => 'rs_1',
                'reasoning_summary' => [],
                'reasoning_encrypted_content' => 'enc-blob-1',
            ],
        ],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages[0]->toolCalls->first())
        ->reasoningId->toBe('rs_1')
        ->reasoningEncryptedContent->toBe('enc-blob-1');
});

test('it rehydrates legacy tool calls that predate reasoning encrypted content', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Legacy conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Looking that up.',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]],
        ],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages[0]->toolCalls->first())
        ->reasoningId->toBeNull()
        ->reasoningSummary->toBeNull()
        ->reasoningEncryptedContent->toBeNull();
});

test('user messages with stored attachments are rehydrated as UserMessage', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Attachment conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
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
    $conversationId = $store->storeConversation(null, '1', 'Multi-attachment conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
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
    $conversationId = $store->storeConversation(null, '1', 'Plain conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
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
    $conversationId = $store->storeConversation(null, '1', 'Malformed attachment conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
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
    $conversationId = $store->storeConversation(null, '1', 'Malformed attachment conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
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
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result_id' => 'result-1'],
        ],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'approval_state' => json_encode(['pending' => ['call-1' => null]]),
        'created' => now(),
        'modified' => now(),
    ]);

    ConversationSchema::saveMessage([
        'id' => 'message-2',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Deleted x',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result' => 'Deleted x', 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls->first()->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Deleted x');
});

test('storing approval results for a conversation with no paused row throws', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    expect(fn() => $store->storeApprovalResults($conversationId, null, '1', [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]))->toThrow(ApprovalMismatchException::class, 'The approval results do not match a paused conversation turn.');
});

test('resolving approval results progressively empties the pause marker while outcomes land on the tool results', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result_id' => 'result-1'],
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'y'], 'result_id' => 'result-2'],
        ],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'approval_state' => json_encode(['pending' => ['call-1' => 'Deletes x', 'call-2' => 'Deletes y']]),
        'created' => now(),
        'modified' => now(),
    ]);

    $store->storeApprovalResults($conversationId, null, '1', [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]);

    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $partial = json_decode((string)$messagesTable->get('message-1')->approval_state, true);

    $store->storeApprovalResults($conversationId, null, '1', [
        new ToolResult('call-2', 'delete_file', ['path' => 'y'], 'The user rejected this tool call.', denied: true),
    ]);

    $record = $messagesTable->get('message-1');
    $results = collect($record->tool_results);

    expect($partial)->toBe(['pending' => ['call-2' => 'Deletes y']])
        ->and(json_decode((string)$record->approval_state, true))->toBe(['pending' => []])
        ->and($results->filter(fn($r): bool => $r['id'] === 'call-1')->first())->not->toHaveKey('denied')
        ->and($results->filter(fn($r): bool => $r['id'] === 'call-2')->first()['denied'])->toBeTrue();
});

test('it keeps a tool call answered on a later row even after its paused row cleared the pending marker', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result_id' => 'result-1'],
        ],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'approval_state' => json_encode(['pending' => []]),
        'created' => now(),
        'modified' => now(),
    ]);

    ConversationSchema::saveMessage([
        'id' => 'message-2',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Deleted x',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result' => 'Deleted x', 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls->first()->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Deleted x');
});

test('it splits a mid-run pause row so an executed call is answered before the still-pending call', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Let me delete b too',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result_id' => 'result-1'],
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result_id' => 'result-2'],
        ],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a', 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => [],
        'approval_state' => json_encode(['pending' => ['call-2' => null]]),
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls->first()->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Let me delete b too')
        ->and($messages[2]->toolCalls)->toHaveCount(1)
        ->and($messages[2]->toolCalls->first()->id)->toBe('call-2');
});

test('it preserves provider content blocks when a mixed pause carries an executed and a gated call', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Let me delete b too',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result_id' => 'result-1'],
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result_id' => 'result-2'],
        ],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a', 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => ['provider_content_blocks' => [['type' => 'thinking', 'signature' => 'sig-1']]],
        'approval_state' => json_encode(['pending' => ['call-2' => null]]),
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls->map(fn($c): string => $c->id)->toList())->toBe(['call-1', 'call-2'])
        ->and($messages[0]->providerContentBlocks)->toBe([['type' => 'thinking', 'signature' => 'sig-1']])
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults->first()->id)->toBe('call-1');
});

test('it drops a leading orphaned tool_result when the row window splits a pause from its resume', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Deleted a',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a', 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10)->toList();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('Deleted a');
});

test('it merges a re-paused turn text into the new tool_use message rather than emitting two assistant messages', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation(null, '1', 'Tool conversation');

    ConversationSchema::saveMessage([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result_id' => 'result-1'],
        ],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'approval_state' => json_encode(['pending' => ['call-1' => null]]),
        'created' => now(),
        'modified' => now(),
    ]);

    ConversationSchema::saveMessage([
        'id' => 'message-2',
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => '1',
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Let me delete that file',
        'attachments' => [],
        'tool_calls' => [
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result_id' => 'result-2'],
        ],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a', 'result_id' => 'result-1'],
        ],
        'usage' => [],
        'meta' => [],
        'approval_state' => json_encode(['pending' => ['call-2' => null]]),
        'created' => now(),
        'modified' => now(),
    ]);

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

test('it records provider content blocks into the message meta when a turn pauses', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Delete config/app.php.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = (new AgentResponse('invocation-id', '', new Usage(), new Meta()))
        ->withMessages(collection([
            new AssistantMessage('Let me think about that', null, [['type' => 'thinking', 'signature' => 'sig-1']]),
        ]));

    $response->withPendingApprovals(collection([
        new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
    ]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->meta)->toHaveKey('provider_content_blocks', [['type' => 'thinking', 'signature' => 'sig-1']]);
});

test('a bare rejection resume does not persist a blank assistant row', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

    ConversationSchema::saveMessage([
        'id' => 'paused-1',
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => [],
        'tool_calls' => [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]],
        'tool_results' => [
            ['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => [], 'result' => 'The user rejected this tool call.', 'result_id' => null],
        ],
        'usage' => [],
        'meta' => [],
        'approval_state' => json_encode(['pending' => []]),
        'created' => now(),
        'modified' => now(),
    ]);

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        '',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
        approvalDecisions: Decisions::from(['call-1' => Decision::reject()]),
    );

    $response = new AgentResponse('invocation-id', '', new Usage(), new Meta());
    $response->toolResults = collection([
        new ToolResult('call-1', 'DeleteFile', [], 'The user rejected this tool call.'),
    ]);

    $messageId = $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    expect($messageId)->toBeNull();

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    expect($messagesTable->find()->where(['role' => 'assistant'])->count())->toBe(1);
});

test('it omits provider content blocks when the assistant turn is not paused', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Delete config/app.php.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = (new AgentResponse('invocation-id', 'Deleted the file.', new Usage(), new Meta()))
        ->withMessages(collection([
            new AssistantMessage('Deleted the file.', null, [['type' => 'thinking', 'signature' => 'sig-1']]),
        ]));

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->meta)->not->toHaveKey('provider_content_blocks');
});

test('it records provider content blocks into the message meta when a stream pauses', function (): void {
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent(),
        'Delete config/app.php.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = new StreamedAgentResponse('invocation-id', collection([
        new ToolApprovalRequest('event-1', collection([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
        ]), 0, [['type' => 'thinking', 'signature' => 'sig-1']]),
    ]), new Meta());

    $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $record = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();

    expect($record->meta)->toHaveKey('provider_content_blocks', [['type' => 'thinking', 'signature' => 'sig-1']]);
});
