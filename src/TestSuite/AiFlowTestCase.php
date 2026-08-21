<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite;

use Cake\TestSuite\TestCase;

/**
 * Optional PHPUnit base class with agentic flow assertions.
 */
abstract class AiFlowTestCase extends TestCase
{
    use AiFlowTrait;
}
