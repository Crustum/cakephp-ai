<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\TestSuite\Http\RecordedHttp;

/**
 * Proves AiFlowTrait is applied via Pest uses(...)->in('Feature') without a file-level uses().
 */
beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('Pest uses()->in wires AiFlowTrait without a file-level uses() call', function (): void {
    expect(method_exists($this, 'fakeProviderHttp'))->toBeTrue()
        ->and(method_exists($this, 'assertHttpSent'))->toBeTrue()
        ->and(method_exists($this, 'assertAiEventDispatched'))->toBeTrue();

    $this->fakeProviderHttp(['*' => fakeOpenAiResponse('Wired')]);

    agent()->prompt('Uses in wiring', provider: 'openai', model: 'gpt-5.4');

    $this->assertHttpSent(
        fn(RecordedHttp $request): bool => $request->hasUserText('Uses in wiring'),
    );
    $this->assertHttpSentWithModel('gpt-5.4');
    $this->assertAiEventDispatched(AgentPrompted::class);
});

test('fake agents still compose with directory-level AiFlowTrait', function (): void {
    AssistantAgent::fake(['No HTTP']);

    (new AssistantAgent())->prompt('Trait from Pest.php');

    $this->assertAgentPrompted(AssistantAgent::class, 'Trait from Pest.php');
    $this->assertHttpNothingSent();
    $this->assertNoToolsInvoked();
});
