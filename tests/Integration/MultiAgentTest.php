<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\InvokingTool;
use Crustum\Ai\Event\PromptingAgent;
use Crustum\Ai\Event\ToolInvoked;
use Crustum\Ai\Test\Fixtures\Agents\DelegatingAgent;
use Crustum\Ai\Test\Fixtures\Agents\ResearchAgent;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Test\Support\IntegrationPrompts;
use Crustum\Ai\Test\Support\Skips\ApiKey;
use Crustum\Ai\Tools\AgentTool;

test('a parent agent delegates to a sub-agent and the sub-agent runs end-to-end', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    Configure::write('Ai.defaultProvider', $provider);

    $recorder = EventRecorder::start([
        InvokingTool::class,
        AgentPrompted::class,
        PromptingAgent::class,
        ToolInvoked::class,
    ]);

    $response = (new DelegatingAgent())->prompt(
        IntegrationPrompts::prompt('research'),
        provider: $provider,
        model: $model,
    );

    $researchToolCall = $response->toolCalls
        ->filter(fn($call): bool => $call->name === 'research_agent')
        ->first();

    expect($researchToolCall)->not->toBeNull()
        ->and($researchToolCall->arguments)->toHaveKey('task')
        ->and($researchToolCall->arguments['task'])->toBeString()->not->toBeEmpty();

    $recorder->assertMatches(InvokingTool::class, fn($event): bool => $event->tool instanceof AgentTool
        && $event->tool->agent() instanceof ResearchAgent
        && ($event->arguments['task'] ?? null) === $researchToolCall->arguments['task']);

    $researchPrompted = null;

    foreach ($recorder->events as $event) {
        if ($event instanceof AgentPrompted && $event->prompt->agent instanceof ResearchAgent) {
            $researchPrompted = $event;

            break;
        }
    }

    expect($researchPrompted)->not->toBeNull()
        ->and($researchPrompted->prompt->prompt)->toBe($researchToolCall->arguments['task'])
        ->and($researchPrompted->response->text)->toBeString()->not->toBeEmpty();

    $recorder->assertMatches(PromptingAgent::class, fn($event): bool => $event->prompt->agent instanceof ResearchAgent);

    $researchToolResult = $response->toolResults
        ->filter(fn($result): bool => $result->name === 'research_agent')
        ->first();

    expect($researchToolResult)->not->toBeNull()
        ->and($researchToolResult->result)->toBe($researchPrompted->response->text);

    $recorder->assertMatches(ToolInvoked::class, fn($event): bool => $event->tool instanceof AgentTool
        && $event->tool->agent() instanceof ResearchAgent
        && $event->result === $researchPrompted->response->text);
})->with('agent-providers');
