<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\TestCase;

/**
 * Base test case for conversation relationship feature tests.
 */
abstract class ConversationRelationshipTestCase extends AiTestCase
{
    /**
     * @var array<int, string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Ai.Users',
        'plugin.Crustum/Ai.AgentConversations',
        'plugin.Crustum/Ai.AgentConversationMessages',
    ];
}
