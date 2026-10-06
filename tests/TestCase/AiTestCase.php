<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\TestCase;

use Cake\Http\TestSuite\HttpClientTrait;
use Cake\TestSuite\TestCase;
use Crustum\Ai\Files\UntrustedUrl;

/**
 * Base test case for all Ai plugin tests
 */
abstract class AiTestCase extends TestCase
{
    use HttpClientTrait;

    /**
     * Fixtures shared by every Ai plugin test so conversation tables are
     * truncated between tests.
     *
     * @var array<int, string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Ai.Users',
        'plugin.Crustum/Ai.AgentConversations',
        'plugin.Crustum/Ai.AgentConversationMessages',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        UntrustedUrl::resolveUsing(fn(string $host): array => ['93.184.216.34']);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        UntrustedUrl::resolveUsing(null);

        parent::tearDown();
    }
}
