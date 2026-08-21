<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\TestCase;

/**
 * Base test case for database conversation store feature tests.
 */
abstract class DatabaseConversationStoreTestCase extends AiTestCase
{
    /**
     * @var array<int, string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Ai.AgentConversations',
        'plugin.Crustum/Ai.AgentConversationMessages',
    ];
}
