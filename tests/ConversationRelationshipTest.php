<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Crustum\Ai\Model\Entity\Conversation;
use Crustum\Ai\Model\Entity\ConversationMessage;
use Crustum\Ai\Test\Support\Database\ConversationSchema;
use TestApp\Model\Entity\User;

beforeEach(function (): void {
    Configure::write('Ai.conversations.connection', 'test');
});

test('model can retrieve conversations using relationship', function (): void {
    /** @var \TestApp\Model\Table\UsersTable $usersTable */
    $usersTable = $this->getTableLocator()->get('Users');
    $user = $usersTable->saveOrFail($usersTable->newEntity(['name' => 'Taylor']));
    $otherUser = $usersTable->saveOrFail($usersTable->newEntity(['name' => 'Abigail']));

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = $this->getTableLocator()->get('Crustum/Ai.Conversations');

    $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => 'conversation-1',
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'title' => 'First Conversation',
        'created' => now()->subMinutes(10),
        'modified' => now()->subMinutes(10),
    ]));
    $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => 'conversation-2',
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'title' => 'Second Conversation',
        'created' => now()->subMinutes(5),
        'modified' => now()->subMinutes(5),
    ]));
    $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => 'conversation-3',
        'participant_type' => User::class,
        'participant_id' => (string)$otherUser->id,
        'title' => 'Other Conversation',
        'created' => now(),
        'modified' => now(),
    ]));

    $conversations = collect(
        $usersTable->Conversations->find()
            ->where([
                'participant_type' => User::class,
                'participant_id' => (string)$user->id,
            ])
            ->order(['modified' => 'DESC'])
            ->all(),
    );

    expect($conversations)->toHaveCount(2)
        ->and($conversations->extract('id')->toList())->toEqual(['conversation-2', 'conversation-1'])
        ->and($conversations->first())->toBeInstanceOf(Conversation::class);
});

test('conversation can retrieve messages using relationship', function (): void {
    /** @var \TestApp\Model\Table\UsersTable $usersTable */
    $usersTable = $this->getTableLocator()->get('Users');
    $user = $usersTable->saveOrFail($usersTable->newEntity(['name' => 'Taylor']));

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = $this->getTableLocator()->get('Crustum/Ai.Conversations');
    $conversation = $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => 'conversation-1',
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'title' => 'Conversation',
        'created' => now(),
        'modified' => now(),
    ]));

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $messagesTable->saveOrFail($messagesTable->newEntity([
        'id' => 'message-1',
        'conversation_id' => $conversation->id,
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'agent' => 'Agent',
        'role' => 'user',
        'content' => 'Hello',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
        'created' => now(),
        'modified' => now(),
    ]));

    $conversation = $conversationsTable->get($conversation->id, contain: ['ConversationMessages']);
    $messages = collect($conversation->messages);

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBeInstanceOf(ConversationMessage::class)
        ->and($messages->first()->content)->toBe('Hello')
        ->and($messages->first()->attachments)->toBeArray();
});

test('conversation can retrieve its participant using relationship', function (): void {
    /** @var \TestApp\Model\Table\UsersTable $usersTable */
    $usersTable = $this->getTableLocator()->get('Users');
    $user = $usersTable->saveOrFail($usersTable->newEntity(['name' => 'Taylor']));

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = $this->getTableLocator()->get('Crustum/Ai.Conversations');
    $conversation = $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => 'conversation-1',
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'title' => 'Conversation',
        'created' => now(),
        'modified' => now(),
    ]));

    expect($conversation->participant)->toBeInstanceOf(User::class)
        ->and($conversation->participant->id)->toBe($user->id);
});

test('conversation model uses configured database connection', function (): void {
    ConversationSchema::create('secondary');

    Configure::write('Ai.conversations.connection', 'secondary');
    TableRegistry::getTableLocator()->clear();

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = TableRegistry::getTableLocator()->get('Crustum/Ai.Conversations');
    $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => 'secondary-conversation-1',
        'participant_type' => null,
        'participant_id' => '1',
        'title' => 'On Secondary DB',
        'created' => now(),
        'modified' => now(),
    ]));

    $conversation = $conversationsTable->get('secondary-conversation-1');

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $testConversationsTable */
    $testConversationsTable = TableRegistry::getTableLocator()->get('TestAgentConversations', [
        'className' => 'Crustum/Ai.Conversations',
        'connectionName' => 'test',
    ]);

    expect($conversation)->not->toBeNull()
        ->and($conversation->title)->toBe('On Secondary DB')
        ->and($testConversationsTable->exists(['id' => 'secondary-conversation-1']))->toBeFalse();
})->after(function (): void {
    Configure::write('Ai.conversations.connection', 'test');
    TableRegistry::getTableLocator()->clear();
    ConnectionManager::get('secondary')->execute('DELETE FROM agent_conversation_messages');
    ConnectionManager::get('secondary')->execute('DELETE FROM agent_conversations');
});
