<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite;

use Cake\Core\Configure;
use Cake\Queue\TestSuite\TestQueueClient;
use Closure;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Responses\TextResponse;
use Crustum\Ai\TestSuite\Capture\FlowCapture;
use Crustum\Ai\TestSuite\Http\HttpResponseDefinition;
use Crustum\Ai\TestSuite\Http\HttpResponseSequence;
use Crustum\Ai\TestSuite\Http\RecordedHttp;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Agentic flow testing trait.
 *
 * Captures provider HTTP traffic, AI events, tools, and steps for PHPUnit and Pest.
 *
 * Usage (PHPUnit):
 * ```
 * class MyTest extends TestCase
 * {
 *     use AiFlowTrait;
 *
 *     public function testRequest(): void
 *     {
 *         $this->fakeProviderHttp(['*' => $this->httpResponse(['ok' => true])]);
 *         // ...
 *         $this->assertHttpSent(fn (RecordedHttp $r) => $r->json('ok') === true);
 *     }
 * }
 * ```
 *
 * Usage (Pest):
 * ```
 * uses(\Crustum\Ai\TestSuite\AiFlowTrait::class);
 * ```
 */
trait AiFlowTrait
{
    /**
     * Reset event/stream capture before each test.
     *
     * Priority is lower than Cake TestCase::setUp (0) so listeners bind after
     * EventManager::instance(new EventManager()) replaces the global manager.
     *
     * Does not reset HTTP fakes — Pest/user `beforeEach` often calls `aiHttpFake()`
     * / `fakeProviderHttp()` before this hook runs.
     *
     * @return void
     */
    #[Before(-1)]
    public function setupAiFlowCapture(): void
    {
        FlowCapture::begin();
    }

    /**
     * Reset all flow captures after each test.
     *
     * Also resets CrustumQueue sync mode to off and clears captured queue jobs so
     * queued-generation tests never leak dispatch state into the next test.
     *
     * @return void
     */
    #[After(1)]
    public function cleanupAiFlowCapture(): void
    {
        Configure::write('CrustumQueue.sync', false);
        TestQueueClient::clearQueuedJobs();

        FlowCapture::reset();
    }

    /**
     * Limit captured AI event classes for the current test.
     *
     * @param list<class-string<\Crustum\Ai\Event\AiEvent>> $eventClasses Event classes
     * @return void
     */
    public function captureAiEvents(array $eventClasses): void
    {
        AiFlow::captureAiEvents($eventClasses);
    }

    /**
     * Manually observe a response for step and usage assertions.
     *
     * @param \Crustum\Ai\Responses\TextResponse|\Crustum\Ai\Responses\AgentResponse|\Crustum\Ai\Responses\StreamedAgentResponse $response Response
     * @return void
     */
    public function observe(TextResponse|AgentResponse|StreamedAgentResponse $response): void
    {
        AiFlow::observe($response);
    }

    /**
     * Consume a streamable response and record its stream events.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Crustum\Ai\Responses\StreamableAgentResponse
     */
    public function observeStream(StreamableAgentResponse $response): StreamableAgentResponse
    {
        return AiFlow::observeStream($response);
    }

    /**
     * Fake provider HTTP requests.
     *
     * @param callable|array<string, mixed>|null $definition URL patterns or callback
     * @return void
     */
    public function fakeProviderHttp(array|callable|null $definition = null): void
    {
        AiFlow::fakeProviderHttp($definition);
    }

    /**
     * Create a fake HTTP response definition.
     *
     * @param array<string, mixed>|string $body Response body
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return \Crustum\Ai\TestSuite\Http\HttpResponseDefinition
     */
    public function httpResponse(
        array|string $body = [],
        int $status = 200,
        array $headers = [],
    ): HttpResponseDefinition {
        return AiFlow::httpResponse($body, $status, $headers);
    }

    /**
     * Create a sequence of fake HTTP responses.
     *
     * @param array<int, \Crustum\Ai\TestSuite\Http\HttpResponseDefinition> $responses Response definitions
     * @return \Crustum\Ai\TestSuite\Http\HttpResponseSequence
     */
    public function httpSequence(array $responses): HttpResponseSequence
    {
        return AiFlow::httpSequence($responses);
    }

    /**
     * Assert that a matching provider HTTP request was sent.
     *
     * @param callable $callback Truth test receiving RecordedHttp
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertHttpSent(callable $callback, string $message = ''): void
    {
        AiFlow::assertHttpSent($callback, $message);
    }

    /**
     * Assert that a provider HTTP request was sent to a URL pattern.
     *
     * @param string $pattern Method and URL pattern
     * @param callable|null $callback Optional additional request truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertHttpSentTo(
        string $pattern,
        ?callable $callback = null,
        string $message = '',
    ): void {
        AiFlow::assertHttpSentTo($pattern, $callback, $message);
    }

    /**
     * Assert that a provider HTTP request used a model.
     *
     * @param string $model Expected model
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertHttpSentWithModel(string $model, string $message = ''): void
    {
        AiFlow::assertHttpSentWithModel($model, $message);
    }

    /**
     * Assert the number of recorded provider HTTP requests.
     *
     * @param int $count Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertHttpSentCount(int $count, string $message = ''): void
    {
        AiFlow::assertHttpSentCount($count, $message);
    }

    /**
     * Assert how many provider HTTP requests matched an optional truth test.
     *
     * @param int $times Expected count
     * @param callable|null $callback Optional request truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertHttpSentTimes(
        int $times,
        ?callable $callback = null,
        string $message = '',
    ): void {
        AiFlow::assertHttpSentTimes($times, $callback, $message);
    }

    /**
     * Assert that provider HTTP requests matched ordered truth tests.
     *
     * @param array<int, callable> $callbacks Ordered truth tests
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertHttpSentInOrder(array $callbacks, string $message = ''): void
    {
        AiFlow::assertHttpSentInOrder($callbacks, $message);
    }

    /**
     * Assert that no provider HTTP requests were sent.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertHttpNothingSent(string $message = ''): void
    {
        AiFlow::assertHttpNothingSent($message);
    }

    /**
     * Get recorded HTTP request pairs.
     *
     * @param callable|null $filter Optional request filter
     * @return array<int, array{0: \Crustum\Ai\TestSuite\Http\RecordedHttp, 1: \Crustum\Ai\Http\Contract\HttpResponseInterface}>
     */
    public function getHttpRequests(?callable $filter = null): array
    {
        return AiFlow::getHttpRequests($filter);
    }

    /**
     * Get recorded HTTP requests only.
     *
     * @return array<int, \Crustum\Ai\TestSuite\Http\RecordedHttp>
     */
    public function getRecordedHttpRequests(): array
    {
        return AiFlow::getRecordedHttpRequests();
    }

    /**
     * Get the first recorded HTTP request.
     *
     * @return \Crustum\Ai\TestSuite\Http\RecordedHttp
     */
    public function getFirstHttpRequest(): RecordedHttp
    {
        return AiFlow::getFirstHttpRequest();
    }

    /**
     * Assert a job was pushed onto the queue.
     *
     * @param class-string $jobClass Job class
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertJobPushed(string $jobClass, string $message = ''): void
    {
        AiFlow::assertJobPushed($jobClass, $message);
    }

    /**
     * Assert a job was pushed onto the queue a specific number of times.
     *
     * @param class-string $jobClass Job class
     * @param int $times Expected push count
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertJobPushedTimes(string $jobClass, int $times, string $message = ''): void
    {
        AiFlow::assertJobPushedTimes($jobClass, $times, $message);
    }

    /**
     * Assert no job (optionally for a class) was pushed onto the queue.
     *
     * @param class-string|null $jobClass Job class, or null to assert none were pushed
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertNoJobsPushed(?string $jobClass = null, string $message = ''): void
    {
        AiFlow::assertNoJobsPushed($jobClass, $message);
    }

    /**
     * Assert that an AI event was dispatched.
     *
     * @param class-string<\Crustum\Ai\Event\AiEvent> $eventClass Event class
     * @param callable|null $callback Optional truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertAiEventDispatched(
        string $eventClass,
        ?callable $callback = null,
        string $message = '',
    ): void {
        AiFlow::assertAiEventDispatched($eventClass, $callback, $message);
    }

    /**
     * Assert that an AI event was not dispatched.
     *
     * @param class-string<\Crustum\Ai\Event\AiEvent> $eventClass Event class
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertAiEventNotDispatched(string $eventClass, string $message = ''): void
    {
        AiFlow::assertAiEventNotDispatched($eventClass, $message);
    }

    /**
     * Assert how many times an AI event was dispatched.
     *
     * @param class-string<\Crustum\Ai\Event\AiEvent> $eventClass Event class
     * @param int $count Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertAiEventCount(string $eventClass, int $count, string $message = ''): void
    {
        AiFlow::assertAiEventCount($eventClass, $count, $message);
    }

    /**
     * Assert AI events were dispatched in order as a subsequence.
     *
     * @param list<class-string<\Crustum\Ai\Event\AiEvent>> $eventClasses Ordered event classes
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertAiEventsInOrder(array $eventClasses, string $message = ''): void
    {
        AiFlow::assertAiEventsInOrder($eventClasses, $message);
    }

    /**
     * Get recorded AI events.
     *
     * @return array<int, \Crustum\Ai\Event\AiEvent>
     */
    public function getAiEvents(): array
    {
        return AiFlow::getAiEvents();
    }

    /**
     * Assert a tool was invoked.
     *
     * @param string $name Tool name
     * @param callable|null $callback Optional truth test on RecordedToolInvocation
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertToolInvoked(
        string $name,
        ?callable $callback = null,
        string $message = '',
    ): void {
        AiFlow::assertToolInvoked($name, $callback, $message);
    }

    /**
     * Assert a tool was not invoked.
     *
     * @param string $name Tool name
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertToolNotInvoked(string $name, string $message = ''): void
    {
        AiFlow::assertToolNotInvoked($name, $message);
    }

    /**
     * Assert a tool was invoked a specific number of times.
     *
     * @param string $name Tool name
     * @param int $times Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertToolInvokedTimes(string $name, int $times, string $message = ''): void
    {
        AiFlow::assertToolInvokedTimes($name, $times, $message);
    }

    /**
     * Assert tools were invoked in order as a subsequence.
     *
     * @param list<string> $names Ordered tool names
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertToolsInvokedInOrder(array $names, string $message = ''): void
    {
        AiFlow::assertToolsInvokedInOrder($names, $message);
    }

    /**
     * Assert that no tools were invoked.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertNoToolsInvoked(string $message = ''): void
    {
        AiFlow::assertNoToolsInvoked($message);
    }

    /**
     * Assert a tool result contains a fragment.
     *
     * @param string $name Tool name
     * @param mixed $fragment Expected fragment
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertToolResultContains(
        string $name,
        mixed $fragment,
        string $message = '',
    ): void {
        AiFlow::assertToolResultContains($name, $fragment, $message);
    }

    /**
     * Get recorded tool invocations.
     *
     * @return array<int, \Crustum\Ai\TestSuite\Tool\RecordedToolInvocation>
     */
    public function getToolInvocations(): array
    {
        return AiFlow::getToolInvocations();
    }

    /**
     * Assert the recorded agent step count.
     *
     * @param int $count Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertStepCount(int $count, string $message = ''): void
    {
        AiFlow::assertStepCount($count, $message);
    }

    /**
     * Assert that recorded steps did not exceed the max step budget.
     *
     * @param int $max Maximum allowed steps
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertMaxStepsHonored(int $max, string $message = ''): void
    {
        AiFlow::assertMaxStepsHonored($max, $message);
    }

    /**
     * Assert the last recorded step finish reason.
     *
     * @param \Crustum\Ai\Responses\Data\FinishReason $finishReason Expected finish reason
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertLastStepFinishReason(
        FinishReason $finishReason,
        string $message = '',
    ): void {
        AiFlow::assertLastStepFinishReason($finishReason, $message);
    }

    /**
     * Assert a tool name appears in recorded steps.
     *
     * @param string $name Tool name
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertStepsContainTool(string $name, string $message = ''): void
    {
        AiFlow::assertStepsContainTool($name, $message);
    }

    /**
     * Assert observed response usage equals the sum of step usages.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertUsageAccumulated(string $message = ''): void
    {
        AiFlow::assertUsageAccumulated($message);
    }

    /**
     * Get recorded steps.
     *
     * @return array<int, \Crustum\Ai\Responses\Data\Step>
     */
    public function getSteps(): array
    {
        return AiFlow::getSteps();
    }

    /**
     * Assert a stream event class was emitted.
     *
     * @param class-string<\Crustum\Ai\Streaming\Event\StreamEvent> $eventClass Event class
     * @param callable|null $callback Optional truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertStreamEmitted(
        string $eventClass,
        ?callable $callback = null,
        string $message = '',
    ): void {
        AiFlow::assertStreamEmitted($eventClass, $callback, $message);
    }

    /**
     * Assert stream events were emitted in order as a subsequence.
     *
     * @param list<class-string<\Crustum\Ai\Streaming\Event\StreamEvent>> $eventClasses Ordered event classes
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertStreamSequence(array $eventClasses, string $message = ''): void
    {
        AiFlow::assertStreamSequence($eventClasses, $message);
    }

    /**
     * Assert concatenated stream text contains a fragment.
     *
     * @param string $needle Expected text fragment
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertStreamTextContains(string $needle, string $message = ''): void
    {
        AiFlow::assertStreamTextContains($needle, $message);
    }

    /**
     * Assert a stream tool call was emitted for a tool name.
     *
     * @param string $name Tool name
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertStreamToolCall(string $name, string $message = ''): void
    {
        AiFlow::assertStreamToolCall($name, $message);
    }

    /**
     * Get recorded stream events.
     *
     * @return array<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    public function getStreamEvents(): array
    {
        return AiFlow::getStreamEvents();
    }

    /**
     * Assert the number of pending tool approvals.
     *
     * @param int $count Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertPendingApprovals(int $count, string $message = ''): void
    {
        AiFlow::assertPendingApprovals($count, $message);
    }

    /**
     * Assert a pending approval exists for a tool.
     *
     * @param string $tool Tool name
     * @param callable|null $callback Optional truth test on PendingApproval
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertPendingApproval(
        string $tool,
        ?callable $callback = null,
        string $message = '',
    ): void {
        AiFlow::assertPendingApproval($tool, $callback, $message);
    }

    /**
     * Assert there are no pending tool approvals.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertNoPendingApprovals(string $message = ''): void
    {
        AiFlow::assertNoPendingApprovals($message);
    }

    /**
     * Assert a resume prompt recorded an approval decision for a tool call.
     *
     * @param string $callId Tool call identifier
     * @param bool $approved Whether approval was expected
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertApprovalDecision(
        string $callId,
        bool $approved,
        string $message = '',
    ): void {
        AiFlow::assertApprovalDecision($callId, $approved, $message);
    }

    /**
     * Assert a provider failover occurred.
     *
     * @param string $from Provider that failed
     * @param string|null $to Optional provider failed over to
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertProviderFailedOver(
        string $from,
        ?string $to = null,
        string $message = '',
    ): void {
        AiFlow::assertProviderFailedOver($from, $to, $message);
    }

    /**
     * Assert provider HTTP requests matched providers in order.
     *
     * @param list<string> $providers Ordered provider names
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertHttpSentToProvidersInOrder(array $providers, string $message = ''): void
    {
        AiFlow::assertHttpSentToProvidersInOrder($providers, $message);
    }

    /**
     * Assert a faked agent was prompted.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agentClass Agent class
     * @param \Closure|string $callback Prompt text or truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertAgentPrompted(
        string $agentClass,
        Closure|string $callback,
        string $message = '',
    ): void {
        AiFlow::assertAgentPrompted($agentClass, $callback, $message);
    }

    /**
     * Assert a faked agent was never prompted.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agentClass Agent class
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertAgentNeverPrompted(string $agentClass, string $message = ''): void
    {
        AiFlow::assertAgentNeverPrompted($agentClass, $message);
    }

    /**
     * Assert only the given agent classes were prompted.
     *
     * @param list<class-string<\Crustum\Ai\Contracts\Agent>> $agentClasses Expected agent classes
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertOnlyAgentsPrompted(array $agentClasses, string $message = ''): void
    {
        AiFlow::assertOnlyAgentsPrompted($agentClasses, $message);
    }

    /**
     * Assert a sub-agent class was prompted.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agentClass Sub-agent class
     * @param \Closure|string|null $callback Optional prompt text or truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertSubAgentPrompted(
        string $agentClass,
        Closure|string|null $callback = null,
        string $message = '',
    ): void {
        AiFlow::assertSubAgentPrompted($agentClass, $callback, $message);
    }

    /**
     * Assert work was handed off to a sub-agent class.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agentClass Sub-agent class
     * @param \Closure|string|null $callback Optional prompt text or truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertHandoffTo(
        string $agentClass,
        Closure|string|null $callback = null,
        string $message = '',
    ): void {
        AiFlow::assertHandoffTo($agentClass, $callback, $message);
    }

    /**
     * Assert the root (parent) agent did not invoke a tool by name.
     *
     * @param string $name Tool name
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertParentDidNotInvokeTool(string $name, string $message = ''): void
    {
        AiFlow::assertParentDidNotInvokeTool($name, $message);
    }

    /**
     * Assert the last response message count.
     *
     * @param int $count Expected message count
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertConversationMessageCount(int $count, string $message = ''): void
    {
        AiFlow::assertConversationMessageCount($count, $message);
    }

    /**
     * Assert the last response contains a message with the given role.
     *
     * @param \Crustum\Ai\Messages\MessageRole|string $role Message role
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertConversationContainsRole(
        MessageRole|string $role,
        string $message = '',
    ): void {
        AiFlow::assertConversationContainsRole($role, $message);
    }

    /**
     * Assert recorded responses share one conversation id across prompts.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertRememberedAcrossPrompts(string $message = ''): void
    {
        AiFlow::assertRememberedAcrossPrompts($message);
    }
}
