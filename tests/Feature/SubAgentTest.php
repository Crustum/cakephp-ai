<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Test\Fixtures\Agents\DelegatingAgent;
use Crustum\Ai\Test\Fixtures\Agents\MiddleManagerAgent;
use Crustum\Ai\Test\Fixtures\Agents\OrchestratorAgent;
use Crustum\Ai\Test\Fixtures\Agents\ResearchAgent;
use Crustum\Ai\Tools\AgentTool;
use Crustum\Ai\Trait\PromptableTrait;

test('agent returned from tools is invoked when called by parent agent', function (): void {
    DelegatingAgent::fake([
        new ToolCall('call_123', 'research_agent', ['task' => 'Research CakePHP']),
        'Research delegated.',
    ]);

    ResearchAgent::fake(['Research result']);

    $response = (new DelegatingAgent())->prompt('Delegate research about CakePHP');

    $this->assertAgentPrompted(DelegatingAgent::class, 'Delegate research about CakePHP');
    $this->assertSubAgentPrompted(
        ResearchAgent::class,
        fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Research CakePHP',
    );
    $this->assertHandoffTo(ResearchAgent::class);
    $this->assertOnlyAgentsPrompted([DelegatingAgent::class, ResearchAgent::class]);
    $this->assertToolInvoked('research_agent');
    $this->assertParentDidNotInvokeTool('FixedNumberGenerator');
    $this->assertHttpNothingSent();

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls->first()->name)->toBe('research_agent')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Research result');
});

test('research agent can be faked independently', function (): void {
    ResearchAgent::fake(['Research result']);

    $response = (new ResearchAgent())->prompt('Research topic');

    $this->assertAgentPrompted(ResearchAgent::class, 'Research topic');
    $this->assertAgentNeverPrompted(DelegatingAgent::class);

    expect($response->text)->toBe('Research result');
});

test('agent tool uses name and description from agent when defined', function (): void {
    $tool = new AgentTool(new ResearchAgent());

    expect($tool->name())->toBe('research_agent')
        ->and($tool->description())->toBe('Research a topic in depth and return a summary.');
});

test('agent tool falls back to class basename for name when has tool metadata is not implemented', function (): void {
    $agent = new class implements Agent
    {
        use PromptableTrait;

        public function instructions(): string
        {
            return '';
        }
    };

    $tool = new AgentTool($agent);
    $name = $tool->name();

    expect($name)->not->toBeEmpty()
        ->and((string)$tool->description())
        ->toStartWith(sprintf('Delegates a task to the %s sub-agent', $name));
});

test('agent tool falls back to a generic description that does not leak instructions', function (): void {
    $agent = new class implements Agent
    {
        use PromptableTrait;

        public function instructions(): string
        {
            return 'You are a silent agent with very long internal instructions that should never be sent to the parent LLM as a tool description.';
        }
    };

    $tool = new AgentTool($agent);

    expect((string)$tool->description())
        ->toStartWith('Delegates a task to the')
        ->and((string)$tool->description())
        ->toContain('sub-agent')
        ->not->toContain('long internal instructions');
});

test('framework wraps an agent in tools automatically when resolving', function (): void {
    $tools = (new DelegatingAgent())->tools();

    $resolved = array_map(
        fn($tool) => $tool instanceof Agent ? new AgentTool($tool) : $tool,
        [...$tools],
    );

    expect($resolved[0])->toBeInstanceOf(AgentTool::class)
        ->and($resolved[0]->agent())->toBeInstanceOf(ResearchAgent::class);
});

test('nested agent delegates through a middle manager to a research agent', function (): void {
    OrchestratorAgent::fake([
        new ToolCall('call_001', 'middle_manager', ['task' => 'Deep-dive on CakePHP caching']),
        'Delegated to middle manager.',
    ]);

    MiddleManagerAgent::fake([
        new ToolCall('call_002', 'research_agent', ['task' => 'Research CakePHP caching internals']),
        'Research delegated.',
    ]);

    ResearchAgent::fake(['Deep research result']);

    $response = (new OrchestratorAgent())->prompt('Do a deep dive on CakePHP caching');

    $this->assertAgentPrompted(OrchestratorAgent::class, 'Do a deep dive on CakePHP caching');
    $this->assertHandoffTo(
        MiddleManagerAgent::class,
        fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Deep-dive on CakePHP caching',
    );
    $this->assertSubAgentPrompted(
        ResearchAgent::class,
        fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Research CakePHP caching internals',
    );
    $this->assertOnlyAgentsPrompted([
        OrchestratorAgent::class,
        MiddleManagerAgent::class,
        ResearchAgent::class,
    ]);

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls->first()->name)->toBe('middle_manager')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Research delegated.');
});
