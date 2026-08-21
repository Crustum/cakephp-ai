<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\TestCase;

use Cake\Http\TestSuite\HttpClientTrait;
use Cake\TestSuite\TestCase;

/**
 * Base test case for all Ai plugin tests
 */
abstract class AiTestCase extends TestCase
{
    use HttpClientTrait;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
