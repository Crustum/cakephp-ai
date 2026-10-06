<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Queue\QueueManager;
use Cake\Queue\TestSuite\TestQueueClient;
use Crustum\Ai\Ai;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\AgentStreamed;
use Crustum\Ai\Event\PromptingAgent;
use Crustum\Ai\Event\StartingStep;
use Crustum\Ai\Event\StepCompleted;
use Crustum\Ai\Event\ToolInvoked;
use Crustum\Ai\Job\GenerateAudioJob;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Fixtures\FakeConversationStore;
use Crustum\Ai\TestSuite\AiFlow;
use Crustum\Ai\TestSuite\Http\RecordedHttp;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('Pest can fake and assert provider requests through the flow trait', function (): void {
    $this->fakeProviderHttp(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'openai', model: 'gpt-5.4');

    $this->assertHttpSent(
        fn(RecordedHttp $request): bool => $request->json('model') === 'gpt-5.4'
            && $request->hasUserText('Hi there'),
    );
    $this->assertHttpSentTo('POST api.openai.com/*');
    $this->assertHttpSentWithModel('gpt-5.4');
    $this->assertHttpSentTimes(
        1,
        fn(RecordedHttp $request): bool => $request->hasUserText('Hi there'),
    );
});

test('static flow assertions share the trait capture', function (): void {
    $this->fakeProviderHttp(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Static assertion', provider: 'openai');

    AiFlow::assertHttpSent(
        fn(RecordedHttp $request): bool => $request->hasUserText('Static assertion'),
    );
});

test('flow capture is reset between tests', function (): void {
    $this->assertHttpNothingSent();
});

test('flow teardown resets queue sync mode and captured jobs', function (): void {
    aiFakeQueue();
    aiSyncQueue();
    QueueManager::push(GenerateAudioJob::class, ['pending' => 'x']);
    $this->assertJobPushed(GenerateAudioJob::class);

    $this->cleanupAiFlowCapture();

    expect((bool)Configure::read('CrustumQueue.sync'))->toBeFalse();
    expect(TestQueueClient::getQueuedJobs())->toBeEmpty();
});

test('failed request assertions include the recorded timeline', function (): void {
    $this->fakeProviderHttp(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Timeline', provider: 'openai', model: 'gpt-5.4');

    expect(
        fn() => $this->assertHttpSent(fn(RecordedHttp $request): bool => false),
    )->toThrow(AssertionFailedError::class, 'Recorded (1):');
});

test('fake agent prompts still emit agent events without tools', function (): void {
    AssistantAgent::fake(['Fake response']);

    (new AssistantAgent())->prompt('Hello events');

    $this->assertAiEventDispatched(PromptingAgent::class);
    $this->assertAiEventDispatched(AgentPrompted::class);
    $this->assertAiEventsInOrder([
        PromptingAgent::class,
        AgentPrompted::class,
    ]);
    $this->assertAiEventNotDispatched(ToolInvoked::class);
    $this->assertNoToolsInvoked();
    $this->assertHttpNothingSent();
});

test('provider tool loops record tools steps and events', function (): void {
    $this->fakeProviderHttp([
        '*' => $this->httpSequence([
            aiHttpResponse([
                'id' => 'resp_tool_flow',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'function_call',
                    'id' => 'fc_flow',
                    'call_id' => 'call_flow',
                    'name' => 'FixedNumberGenerator',
                    'arguments' => '{}',
                    'status' => 'completed',
                ]],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                ],
            ]),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'openai',
    );

    $this->assertToolInvoked('FixedNumberGenerator');
    $this->assertToolInvokedTimes('FixedNumberGenerator', 1);
    $this->assertToolNotInvoked('RandomNumberGenerator');
    $this->assertStepsContainTool('FixedNumberGenerator');
    $this->assertStepCount(2);
    $this->assertAiEventDispatched(ToolInvoked::class);
    $this->assertLastStepFinishReason(FinishReason::Stop);
    $this->assertNoPendingApprovals();
});

test('fake agent streams record stream events and text', function (): void {
    AssistantAgent::fake(['Hello stream']);

    $response = (new AssistantAgent())->stream('Hi');
    foreach ($response as $_) {
    }

    $this->assertStreamEmitted(TextDelta::class);
    $this->assertStreamTextContains('Hello');
    $this->assertStreamSequence([
        StreamStart::class,
        TextDelta::class,
    ]);
    $this->assertAiEventDispatched(AgentStreamed::class);
});

test('remembering agents keep the same conversation id across prompts', function (): void {
    Ai::manager()->setConversationStore(new FakeConversationStore());

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];
    $agent = (new RememberingAssistantAgent())->forUser($user);
    RememberingAssistantAgent::fake(['First', 'Second']);

    $first = $agent->prompt('Hello');
    $second = $agent->continue((string)$first->conversationId, $user)->prompt('Again');

    expect($first->conversationId)->not->toBeNull()
        ->and($second->conversationId)->toBe($first->conversationId);

    $this->assertRememberedAcrossPrompts();
    $this->assertAgentPrompted(
        RememberingAssistantAgent::class,
        'Hello',
    );
});

test('multi step responses expose conversation message roles', function (): void {
    $this->fakeProviderHttp([
        '*' => $this->httpSequence([
            aiHttpResponse([
                'id' => 'resp_tool_roles_1',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'function_call',
                    'id' => 'fc_roles_1',
                    'call_id' => 'call_roles_1',
                    'name' => 'FixedNumberGenerator',
                    'arguments' => '{}',
                    'status' => 'completed',
                ]],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                ],
            ]),
            aiHttpResponse([
                'id' => 'resp_tool_roles_2',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'function_call',
                    'id' => 'fc_roles_2',
                    'call_id' => 'call_roles_2',
                    'name' => 'FixedNumberGenerator',
                    'arguments' => '{}',
                    'status' => 'completed',
                ]],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                ],
            ]),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new MultiStepToolAgent())->prompt(
        'Generate numbers',
        provider: 'openai',
    );

    $this->assertConversationMessageCount(5);
    $this->assertConversationContainsRole(MessageRole::Assistant);
    $this->assertConversationContainsRole(MessageRole::ToolResult);
});

test('v4 run-context events and tool durations are captured', function (): void {
    $this->fakeProviderHttp([
        '*' => $this->httpSequence([
            aiHttpResponse([
                'id' => 'resp_v4_flow',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [[
                    'type' => 'function_call',
                    'id' => 'fc_v4',
                    'call_id' => 'call_v4',
                    'name' => 'FixedNumberGenerator',
                    'arguments' => '{}',
                    'status' => 'completed',
                ]],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                ],
            ]),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'openai',
    );

    $this->assertAiEventDispatched(StartingStep::class);
    $this->assertAiEventDispatched(StepCompleted::class);
    $this->assertToolInvoked('FixedNumberGenerator');

    $invocations = $this->getToolInvocations();

    expect($invocations[0]->time)->toBeFloat()->toBeGreaterThan(0.0);
});
