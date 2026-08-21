<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Migrations\BaseMigration;

/**
 * Create agent conversation tables.
 *
 * Includes polymorphic participants and approval_state (pre-release compact schema).
 */
class CreateAgentConversations extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $conversationsTable = (string)Configure::read(
            'Ai.conversations.tables.conversations',
            'agent_conversations',
        );
        $messagesTable = (string)Configure::read(
            'Ai.conversations.tables.messages',
            'agent_conversation_messages',
        );

        $this->table($conversationsTable, ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('participant_id', 'uuid', ['null' => true, 'default' => null])
            ->addColumn('participant_type', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['participant_type', 'participant_id', 'modified'])
            ->create();

        $this->table($messagesTable, ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('conversation_id', 'uuid', ['null' => false])
            ->addColumn('participant_id', 'uuid', ['null' => true, 'default' => null])
            ->addColumn('participant_type', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('agent', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('role', 'string', ['limit' => 25, 'null' => false])
            ->addColumn('content', 'text', ['null' => false])
            ->addColumn('attachments', 'text', ['null' => false])
            ->addColumn('tool_calls', 'text', ['null' => false])
            ->addColumn('tool_results', 'text', ['null' => false])
            ->addColumn('usage_data', 'text', ['null' => false])
            ->addColumn('meta', 'text', ['null' => false])
            ->addColumn('approval_state', 'text', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['conversation_id'])
            ->addIndex(
                ['conversation_id', 'participant_type', 'participant_id', 'modified'],
                ['name' => 'conversation_index'],
            )
            ->addIndex(['participant_type', 'participant_id'])
            ->create();
    }
}
