<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Agent conversation messages fixture.
 */
class AgentConversationMessagesFixture extends TestFixture
{
    public string $table = 'agent_conversation_messages';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [];
}
