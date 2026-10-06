<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;
use Crustum\Ai\Test\Support\Database\ConversationTable;

beforeEach(function (): void {
    Ai::manager()->resetFakeState();
    Ai::manager()->setConversationStore(new DatabaseConversationStore());
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');
});

test('a remembered turn reports the rows it wrote', function (): void {
    RememberingAssistantAgent::fake(['Hello world']);

    $participant = new class {
        public string $id = '11111111-1111-4111-8111-111111111111';
    };

    $response = (new RememberingAssistantAgent())->forUser($participant)->prompt('Hi');

    expect($response->conversationId)->not->toBeNull()
        ->and($response->userMessageId)->not->toBeNull()
        ->and($response->assistantMessageId)->not->toBeNull();

    expect(ConversationTable::first('agent_conversation_messages', ['id' => $response->userMessageId])->role)->toBe('user')
        ->and(ConversationTable::first('agent_conversation_messages', ['id' => $response->assistantMessageId])->role)->toBe('assistant');
});

test('a streamed turn reports the rows it wrote once it has been consumed', function (): void {
    RememberingAssistantAgent::fake(['Hello world']);

    $participant = new class {
        public string $id = '22222222-2222-4222-8222-222222222222';
    };

    $response = (new RememberingAssistantAgent())->forUser($participant)->stream('Hi');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->assistantMessageId)->not->toBeNull()
        ->and(ConversationTable::first('agent_conversation_messages', ['id' => $response->assistantMessageId])->role)
        ->toBe('assistant');
});

test('a turn that stores nothing reports no ids', function (): void {
    RememberingAssistantAgent::fake(['Hello world']);

    $response = (new RememberingAssistantAgent())->prompt('Hi');

    expect($response->conversationId)->toBeNull()
        ->and($response->userMessageId)->toBeNull()
        ->and($response->assistantMessageId)->toBeNull();
});
