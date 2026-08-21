<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite;

use Cake\Core\Configure;
use Closure;
use Crustum\Ai\Ai;
use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\ToolApprovalRequested;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Responses\TextResponse;
use Crustum\Ai\TestSuite\Capture\EventCapture;
use Crustum\Ai\TestSuite\Capture\FlowCapture;
use Crustum\Ai\TestSuite\Capture\HttpCapture;
use Crustum\Ai\TestSuite\Capture\StreamCapture;
use Crustum\Ai\TestSuite\Constraint\Event\AiEventCount;
use Crustum\Ai\TestSuite\Constraint\Event\AiEventDispatched;
use Crustum\Ai\TestSuite\Constraint\Event\AiEventNotDispatched;
use Crustum\Ai\TestSuite\Constraint\Event\AiEventsInOrder;
use Crustum\Ai\TestSuite\Constraint\Failover\ProviderFailedOver;
use Crustum\Ai\TestSuite\Constraint\Http\HttpNothingSent;
use Crustum\Ai\TestSuite\Constraint\Http\HttpRequestCount;
use Crustum\Ai\TestSuite\Constraint\Http\HttpRequestSent;
use Crustum\Ai\TestSuite\Constraint\Http\HttpSentInOrder;
use Crustum\Ai\TestSuite\Constraint\Queue\JobPushed;
use Crustum\Ai\TestSuite\Constraint\Queue\JobPushedTimes;
use Crustum\Ai\TestSuite\Constraint\Queue\NoJobPushed;
use Crustum\Ai\TestSuite\Constraint\Step\StepCount;
use Crustum\Ai\TestSuite\Constraint\Stream\StreamEventEmitted;
use Crustum\Ai\TestSuite\Constraint\Stream\StreamEventSequence;
use Crustum\Ai\TestSuite\Constraint\Stream\StreamTextContains;
use Crustum\Ai\TestSuite\Constraint\Stream\StreamToolCallEmitted;
use Crustum\Ai\TestSuite\Constraint\Tool\ToolsInvokedInOrder;
use Crustum\Ai\TestSuite\Constraint\Tool\ToolWasInvoked;
use Crustum\Ai\TestSuite\Constraint\Tool\ToolWasNotInvoked;
use Crustum\Ai\TestSuite\Http\HttpResponseDefinition;
use Crustum\Ai\TestSuite\Http\HttpResponseSequence;
use Crustum\Ai\TestSuite\Http\RecordedHttp;
use Crustum\Ai\TestSuite\Tool\RecordedToolInvocation;
use PHPUnit\Framework\Assert;

/**
 * Static façade for agentic flow capture and assertions.
 *
 * Usable from PHPUnit and Pest without requiring the trait on every test.
 */
class AiFlow
{
    /**
     * Reset all flow captures.
     *
     * @return void
     */
    public static function reset(): void
    {
        FlowCapture::reset();
    }

    /**
     * Limit captured AI event classes for the current test.
     *
     * @param list<class-string<\Crustum\Ai\Event\AiEvent>> $eventClasses Event classes
     * @return void
     */
    public static function captureAiEvents(array $eventClasses): void
    {
        EventCapture::capture($eventClasses);
    }

    /**
     * Manually observe a response for step and usage assertions.
     *
     * @param \Crustum\Ai\Responses\TextResponse|\Crustum\Ai\Responses\AgentResponse|\Crustum\Ai\Responses\StreamedAgentResponse $response Response
     * @return void
     */
    public static function observe(TextResponse|AgentResponse|StreamedAgentResponse $response): void
    {
        EventCapture::observe($response);
    }

    /**
     * Consume a streamable response and record its stream events.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Crustum\Ai\Responses\StreamableAgentResponse
     */
    public static function observeStream(StreamableAgentResponse $response): StreamableAgentResponse
    {
        EventCapture::ensureListening();

        return EventCapture::observeStream($response);
    }

    /**
     * Fake provider HTTP requests.
     *
     * @param callable|array<string, mixed>|null $definition URL patterns or callback
     * @return void
     */
    public static function fakeProviderHttp(array|callable|null $definition = null): void
    {
        EventCapture::ensureListening();
        HttpCapture::fake($definition);
    }

    /**
     * Create a fake HTTP response definition.
     *
     * @param array<string, mixed>|string $body Response body
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return \Crustum\Ai\TestSuite\Http\HttpResponseDefinition
     */
    public static function httpResponse(
        array|string $body = [],
        int $status = 200,
        array $headers = [],
    ): HttpResponseDefinition {
        return HttpCapture::response($body, $status, $headers);
    }

    /**
     * Create a sequence of fake HTTP responses.
     *
     * @param array<int, \Crustum\Ai\TestSuite\Http\HttpResponseDefinition> $responses Response definitions
     * @return \Crustum\Ai\TestSuite\Http\HttpResponseSequence
     */
    public static function httpSequence(array $responses): HttpResponseSequence
    {
        return HttpCapture::sequence($responses);
    }

    /**
     * Assert that a matching provider HTTP request was sent.
     *
     * @param callable $callback Truth test receiving RecordedHttp
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertHttpSent(callable $callback, string $message = ''): void
    {
        Assert::assertThat($callback, new HttpRequestSent(), $message);
    }

    /**
     * Assert that a provider HTTP request was sent to a URL pattern.
     *
     * Patterns may include an HTTP method and wildcards, for example
     * `POST api.openai.com/*`.
     *
     * @param string $pattern Method and URL pattern
     * @param callable|null $callback Optional additional request truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertHttpSentTo(
        string $pattern,
        ?callable $callback = null,
        string $message = '',
    ): void {
        self::assertHttpSent(
            fn(RecordedHttp $request): bool => HttpCapture::patternMatches(
                $pattern,
                $request->method(),
                $request->url(),
            ) && ($callback === null || $callback($request)),
            $message,
        );
    }

    /**
     * Assert that a provider HTTP request used a model.
     *
     * @param string $model Expected model
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertHttpSentWithModel(string $model, string $message = ''): void
    {
        self::assertHttpSent(
            fn(RecordedHttp $request): bool => $request->json('model') === $model,
            $message,
        );
    }

    /**
     * Assert the number of recorded provider HTTP requests.
     *
     * @param int $count Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertHttpSentCount(int $count, string $message = ''): void
    {
        self::assertHttpSentTimes($count, null, $message);
    }

    /**
     * Assert how many provider HTTP requests matched an optional truth test.
     *
     * @param int $times Expected count
     * @param callable|null $callback Optional request truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertHttpSentTimes(
        int $times,
        ?callable $callback = null,
        string $message = '',
    ): void {
        Assert::assertThat($times, new HttpRequestCount($callback), $message);
    }

    /**
     * Assert that provider HTTP requests matched ordered truth tests.
     *
     * @param array<int, callable> $callbacks Ordered truth tests
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertHttpSentInOrder(array $callbacks, string $message = ''): void
    {
        Assert::assertThat(null, new HttpSentInOrder($callbacks), $message);
    }

    /**
     * Assert that no provider HTTP requests were sent.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertHttpNothingSent(string $message = ''): void
    {
        Assert::assertThat(null, new HttpNothingSent(), $message);
    }

    /**
     * Get recorded HTTP request pairs.
     *
     * @param callable|null $filter Optional request filter
     * @return array<int, array{0: \Crustum\Ai\TestSuite\Http\RecordedHttp, 1: \Crustum\Ai\Http\Contract\HttpResponseInterface}>
     */
    public static function getHttpRequests(?callable $filter = null): array
    {
        return HttpCapture::recorded($filter);
    }

    /**
     * Get recorded HTTP requests only.
     *
     * @return array<int, \Crustum\Ai\TestSuite\Http\RecordedHttp>
     */
    public static function getRecordedHttpRequests(): array
    {
        return HttpCapture::recordedRequests();
    }

    /**
     * Get the first recorded HTTP request.
     *
     * @return \Crustum\Ai\TestSuite\Http\RecordedHttp
     */
    public static function getFirstHttpRequest(): RecordedHttp
    {
        $requests = HttpCapture::recordedRequests();
        Assert::assertNotEmpty($requests, 'No HTTP requests were recorded.');

        return $requests[0];
    }

    /**
     * Assert a job was pushed onto the queue.
     *
     * @param class-string $jobClass Job class
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertJobPushed(string $jobClass, string $message = ''): void
    {
        Assert::assertThat($jobClass, new JobPushed($jobClass), $message);
    }

    /**
     * Assert a job was pushed onto the queue a specific number of times.
     *
     * @param class-string $jobClass Job class
     * @param int $times Expected push count
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertJobPushedTimes(string $jobClass, int $times, string $message = ''): void
    {
        Assert::assertThat($jobClass, new JobPushedTimes($jobClass, $times), $message);
    }

    /**
     * Assert no job (optionally for a class) was pushed onto the queue.
     *
     * @param class-string|null $jobClass Job class, or null to assert none were pushed
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertNoJobsPushed(?string $jobClass = null, string $message = ''): void
    {
        Assert::assertThat($jobClass, new NoJobPushed($jobClass), $message);
    }

    /**
     * Assert that an AI event was dispatched.
     *
     * @param class-string<\Crustum\Ai\Event\AiEvent> $eventClass Event class
     * @param callable|null $callback Optional truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertAiEventDispatched(
        string $eventClass,
        ?callable $callback = null,
        string $message = '',
    ): void {
        Assert::assertThat(null, new AiEventDispatched($eventClass, $callback), $message);
    }

    /**
     * Assert that an AI event was not dispatched.
     *
     * @param class-string<\Crustum\Ai\Event\AiEvent> $eventClass Event class
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertAiEventNotDispatched(string $eventClass, string $message = ''): void
    {
        Assert::assertThat(null, new AiEventNotDispatched($eventClass), $message);
    }

    /**
     * Assert how many times an AI event was dispatched.
     *
     * @param class-string<\Crustum\Ai\Event\AiEvent> $eventClass Event class
     * @param int $count Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertAiEventCount(string $eventClass, int $count, string $message = ''): void
    {
        Assert::assertThat($count, new AiEventCount($eventClass), $message);
    }

    /**
     * Assert AI events were dispatched in order as a subsequence.
     *
     * @param list<class-string<\Crustum\Ai\Event\AiEvent>> $eventClasses Ordered event classes
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertAiEventsInOrder(array $eventClasses, string $message = ''): void
    {
        Assert::assertThat(null, new AiEventsInOrder($eventClasses), $message);
    }

    /**
     * Get recorded AI events.
     *
     * @return array<int, \Crustum\Ai\Event\AiEvent>
     */
    public static function getAiEvents(): array
    {
        return EventCapture::events();
    }

    /**
     * Assert a tool was invoked.
     *
     * @param string $name Tool name
     * @param callable|null $callback Optional truth test on RecordedToolInvocation
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertToolInvoked(
        string $name,
        ?callable $callback = null,
        string $message = '',
    ): void {
        Assert::assertThat(null, new ToolWasInvoked($name, $callback), $message);
    }

    /**
     * Assert a tool was not invoked.
     *
     * @param string $name Tool name
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertToolNotInvoked(string $name, string $message = ''): void
    {
        Assert::assertThat(null, new ToolWasNotInvoked($name), $message);
    }

    /**
     * Assert a tool was invoked a specific number of times.
     *
     * @param string $name Tool name
     * @param int $times Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertToolInvokedTimes(string $name, int $times, string $message = ''): void
    {
        $actual = count(array_filter(
            EventCapture::tools(),
            fn(RecordedToolInvocation $tool): bool => $tool->name === $name,
        ));

        Assert::assertSame(
            $times,
            $actual,
            ($message !== '' ? $message . "\n" : '')
                . sprintf('Expected tool [%s] to be invoked %d times, got %d.', $name, $times, $actual)
                . "\n" . EventCapture::timeline(),
        );
    }

    /**
     * Assert tools were invoked in order as a subsequence.
     *
     * @param list<string> $names Ordered tool names
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertToolsInvokedInOrder(array $names, string $message = ''): void
    {
        Assert::assertThat(null, new ToolsInvokedInOrder($names), $message);
    }

    /**
     * Assert that no tools were invoked.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertNoToolsInvoked(string $message = ''): void
    {
        Assert::assertSame(
            [],
            EventCapture::tools(),
            ($message !== '' ? $message . "\n" : '') . EventCapture::timeline(),
        );
    }

    /**
     * Assert a tool result contains a fragment.
     *
     * @param string $name Tool name
     * @param mixed $fragment Expected fragment
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertToolResultContains(
        string $name,
        mixed $fragment,
        string $message = '',
    ): void {
        self::assertToolInvoked(
            $name,
            function (RecordedToolInvocation $tool) use ($fragment): bool {
                if (is_string($fragment)) {
                    return str_contains((string)$tool->result, $fragment);
                }

                return $tool->result == $fragment;
            },
            $message,
        );
    }

    /**
     * Get recorded tool invocations.
     *
     * @return array<int, \Crustum\Ai\TestSuite\Tool\RecordedToolInvocation>
     */
    public static function getToolInvocations(): array
    {
        return EventCapture::tools();
    }

    /**
     * Assert the recorded agent step count.
     *
     * @param int $count Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertStepCount(int $count, string $message = ''): void
    {
        Assert::assertThat($count, new StepCount(), $message);
    }

    /**
     * Assert that recorded steps did not exceed the max step budget.
     *
     * Compares against recorded steps when present, otherwise HTTP request count.
     *
     * @param int $max Maximum allowed steps
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertMaxStepsHonored(int $max, string $message = ''): void
    {
        $actual = EventCapture::steps() !== []
            ? count(EventCapture::steps())
            : count(HttpCapture::recordedRequests());

        Assert::assertLessThanOrEqual(
            $max,
            $actual,
            ($message !== '' ? $message . "\n" : '')
                . sprintf('Expected at most %d steps, got %d.', $max, $actual)
                . "\n" . EventCapture::timeline()
                . "\n" . HttpCapture::timeline(),
        );
    }

    /**
     * Assert the last recorded step finish reason.
     *
     * @param \Crustum\Ai\Responses\Data\FinishReason $finishReason Expected finish reason
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertLastStepFinishReason(
        FinishReason $finishReason,
        string $message = '',
    ): void {
        $steps = EventCapture::steps();
        Assert::assertNotEmpty($steps, ($message !== '' ? $message . "\n" : '') . EventCapture::timeline());

        $last = $steps[array_key_last($steps)];
        Assert::assertSame(
            $finishReason,
            $last->finishReason,
            ($message !== '' ? $message . "\n" : '') . EventCapture::timeline(),
        );
    }

    /**
     * Assert a tool name appears in recorded steps.
     *
     * @param string $name Tool name
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertStepsContainTool(string $name, string $message = ''): void
    {
        $found = false;

        foreach (EventCapture::steps() as $step) {
            foreach ($step->toolCalls as $toolCall) {
                if ($toolCall->name === $name) {
                    $found = true;
                    break 2;
                }
            }
        }

        Assert::assertTrue(
            $found,
            ($message !== '' ? $message . "\n" : '')
                . sprintf('Expected steps to contain tool [%s].', $name)
                . "\n" . EventCapture::timeline(),
        );
    }

    /**
     * Assert observed response usage equals the sum of step usages.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertUsageAccumulated(string $message = ''): void
    {
        $response = EventCapture::lastResponse();
        Assert::assertNotNull($response, ($message !== '' ? $message . "\n" : '') . 'No response was observed.');

        $steps = EventCapture::steps();
        Assert::assertNotEmpty($steps, ($message !== '' ? $message . "\n" : '') . EventCapture::timeline());

        $promptTokens = 0;
        $completionTokens = 0;

        foreach ($steps as $step) {
            $promptTokens += $step->usage->promptTokens;
            $completionTokens += $step->usage->completionTokens;
        }

        Assert::assertSame(
            [$promptTokens, $completionTokens],
            [$response->usage->promptTokens, $response->usage->completionTokens],
            ($message !== '' ? $message . "\n" : '') . EventCapture::timeline(),
        );
    }

    /**
     * Get recorded steps.
     *
     * @return array<int, \Crustum\Ai\Responses\Data\Step>
     */
    public static function getSteps(): array
    {
        return EventCapture::steps();
    }

    /**
     * Assert a stream event class was emitted.
     *
     * @param class-string<\Crustum\Ai\Streaming\Event\StreamEvent> $eventClass Event class
     * @param callable|null $callback Optional truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertStreamEmitted(
        string $eventClass,
        ?callable $callback = null,
        string $message = '',
    ): void {
        Assert::assertThat(null, new StreamEventEmitted($eventClass, $callback), $message);
    }

    /**
     * Assert stream events were emitted in order as a subsequence.
     *
     * @param list<class-string<\Crustum\Ai\Streaming\Event\StreamEvent>> $eventClasses Ordered event classes
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertStreamSequence(array $eventClasses, string $message = ''): void
    {
        Assert::assertThat(null, new StreamEventSequence($eventClasses), $message);
    }

    /**
     * Assert concatenated stream text contains a fragment.
     *
     * @param string $needle Expected text fragment
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertStreamTextContains(string $needle, string $message = ''): void
    {
        Assert::assertThat(null, new StreamTextContains($needle), $message);
    }

    /**
     * Assert a stream tool call was emitted for a tool name.
     *
     * @param string $name Tool name
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertStreamToolCall(string $name, string $message = ''): void
    {
        Assert::assertThat(null, new StreamToolCallEmitted($name), $message);
    }

    /**
     * Get recorded stream events.
     *
     * @return array<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    public static function getStreamEvents(): array
    {
        return StreamCapture::events();
    }

    /**
     * Assert the number of pending tool approvals.
     *
     * @param int $count Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertPendingApprovals(int $count, string $message = ''): void
    {
        $actual = count(self::pendingApprovals());

        Assert::assertSame(
            $count,
            $actual,
            ($message !== '' ? $message . "\n" : '')
                . sprintf('Expected %d pending approvals, got %d.', $count, $actual)
                . "\n" . EventCapture::timeline(),
        );
    }

    /**
     * Assert a pending approval exists for a tool.
     *
     * @param string $tool Tool name
     * @param callable|null $callback Optional truth test on PendingApproval
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertPendingApproval(
        string $tool,
        ?callable $callback = null,
        string $message = '',
    ): void {
        $found = array_any(
            self::pendingApprovals(),
            fn(PendingApproval $approval): bool => $approval->tool === $tool
                && ($callback === null || $callback($approval)),
        );

        Assert::assertTrue(
            $found,
            ($message !== '' ? $message . "\n" : '')
                . sprintf('Expected pending approval for tool [%s].', $tool)
                . "\n" . EventCapture::timeline(),
        );
    }

    /**
     * Assert there are no pending tool approvals.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertNoPendingApprovals(string $message = ''): void
    {
        Assert::assertSame(
            [],
            self::pendingApprovals(),
            ($message !== '' ? $message . "\n" : '') . EventCapture::timeline(),
        );
    }

    /**
     * Assert a resume prompt recorded an approval decision for a tool call.
     *
     * @param string $callId Tool call identifier
     * @param bool $approved Whether approval was expected
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertApprovalDecision(
        string $callId,
        bool $approved,
        string $message = '',
    ): void {
        $matched = false;

        foreach (EventCapture::eventsOf(AgentPrompted::class) as $event) {
            if (!$event instanceof AgentPrompted) {
                continue;
            }

            $decision = $event->prompt->approvalDecisions?->get($callId);
            if (!$decision instanceof Decision) {
                continue;
            }

            $isApproved = $decision->isApproved() || $decision->isEdited();
            if ($isApproved === $approved) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue(
            $matched,
            ($message !== '' ? $message . "\n" : '')
                . sprintf(
                    'Expected approval decision for call [%s] to be %s.',
                    $callId,
                    $approved ? 'approved' : 'rejected',
                )
                . "\n" . EventCapture::timeline(),
        );
    }

    /**
     * Assert a provider failover occurred.
     *
     * @param string $from Provider that failed
     * @param string|null $to Optional provider failed over to
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertProviderFailedOver(
        string $from,
        ?string $to = null,
        string $message = '',
    ): void {
        Assert::assertThat(null, new ProviderFailedOver($from, $to), $message);
    }

    /**
     * Assert provider HTTP requests matched providers in order.
     *
     * Each provider consumes the next matching request (same-host drivers still advance).
     *
     * @param list<string> $providers Ordered provider names
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertHttpSentToProvidersInOrder(array $providers, string $message = ''): void
    {
        $requests = HttpCapture::recordedRequests();
        $requestCount = count($requests);
        $index = 0;
        $matched = [];

        foreach ($providers as $provider) {
            $found = false;

            while ($index < $requestCount) {
                if (self::requestMatchesProvider($requests[$index], $provider)) {
                    $matched[] = $provider . ' @ ' . $requests[$index]->url();
                    $found = true;
                    $index++;
                    break;
                }

                $index++;
            }

            Assert::assertTrue(
                $found,
                ($message !== '' ? $message . "\n" : '')
                    . sprintf('Expected HTTP to providers in order: %s', implode(' -> ', $providers))
                    . "\nMatched so far: " . ($matched === [] ? 'none' : implode(', ', $matched))
                    . "\n" . HttpCapture::timeline(),
            );
        }
    }

    /**
     * Collect pending approvals from events or the last response.
     *
     * @return array<int, \Crustum\Ai\Approvals\PendingApproval>
     */
    protected static function pendingApprovals(): array
    {
        $approvals = [];

        foreach (EventCapture::eventsOf(ToolApprovalRequested::class) as $event) {
            if (!$event instanceof ToolApprovalRequested) {
                continue;
            }

            foreach ($event->pendingApprovals as $approval) {
                $approvals[] = $approval;
            }
        }

        if ($approvals !== []) {
            return $approvals;
        }

        $response = EventCapture::lastResponse();
        if ($response instanceof TextResponse && $response->hasPendingApprovals()) {
            return $response->pendingApprovals->toList();
        }

        return [];
    }

    /**
     * Determine whether a recorded request belongs to a configured provider.
     *
     * @param \Crustum\Ai\TestSuite\Http\RecordedHttp $request Recorded request
     * @param string $provider Provider name
     * @return bool
     */
    protected static function requestMatchesProvider(RecordedHttp $request, string $provider): bool
    {
        $config = Configure::read('Ai.providers.' . $provider);
        if (!is_array($config)) {
            return str_contains(strtolower($request->url()), strtolower($provider));
        }

        if (isset($config['url']) && is_string($config['url'])) {
            $host = parse_url($config['url'], PHP_URL_HOST);

            if (is_string($host) && $host !== '' && str_contains($request->url(), $host)) {
                return true;
            }

            if (str_contains($request->url(), $config['url'])) {
                return true;
            }
        }

        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : $provider;
        $hosts = match ($driver) {
            'openai' => ['api.openai.com'],
            'groq' => ['api.groq.com'],
            'openrouter' => ['openrouter.ai'],
            'ollama' => ['localhost', '127.0.0.1'],
            'elevenlabs' => ['api.elevenlabs.io'],
            default => [strtolower($driver)],
        };

        return array_any(
            $hosts,
            fn(string $host): bool => str_contains(strtolower($request->url()), strtolower($host)),
        );
    }

    /**
     * Assert a faked agent was prompted.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agentClass Agent class
     * @param \Closure|string $callback Prompt text or truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertAgentPrompted(
        string $agentClass,
        Closure|string $callback,
        string $message = '',
    ): void {
        Ai::manager()->assertAgentWasPrompted(
            $agentClass,
            $callback,
            null,
            $message !== '' ? $message : null,
        );
    }

    /**
     * Assert a faked agent was never prompted.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agentClass Agent class
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertAgentNeverPrompted(string $agentClass, string $message = ''): void
    {
        Assert::assertEmpty(
            Ai::manager()->recordedAgentPrompts($agentClass),
            $message !== '' ? $message : sprintf('Expected agent [%s] never to be prompted.', $agentClass),
        );
    }

    /**
     * Assert only the given agent classes were prompted.
     *
     * @param list<class-string<\Crustum\Ai\Contracts\Agent>> $agentClasses Expected agent classes
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertOnlyAgentsPrompted(array $agentClasses, string $message = ''): void
    {
        $actual = Ai::manager()->promptedAgentClasses();
        sort($actual);
        $expected = $agentClasses;
        sort($expected);

        Assert::assertSame(
            $expected,
            $actual,
            ($message !== '' ? $message . "\n" : '')
                . 'Expected only these agents to be prompted: ' . implode(', ', $expected)
                . "\nActual: " . ($actual === [] ? 'none' : implode(', ', $actual)),
        );
    }

    /**
     * Assert a sub-agent class was prompted (via AgentTool delegation or direct call).
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agentClass Sub-agent class
     * @param \Closure|string|null $callback Optional prompt text or truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertSubAgentPrompted(
        string $agentClass,
        Closure|string|null $callback = null,
        string $message = '',
    ): void {
        Ai::manager()->assertAgentWasPrompted(
            $agentClass,
            $callback ?? fn(): bool => true,
            null,
            $message !== '' ? $message : sprintf('Expected sub-agent [%s] to be prompted.', $agentClass),
        );
    }

    /**
     * Assert work was handed off to a sub-agent class.
     *
     * Alias of assertSubAgentPrompted for AgentTool-style delegation.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agentClass Sub-agent class
     * @param \Closure|string|null $callback Optional prompt text or truth test
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertHandoffTo(
        string $agentClass,
        Closure|string|null $callback = null,
        string $message = '',
    ): void {
        self::assertSubAgentPrompted($agentClass, $callback, $message);
    }

    /**
     * Assert the root (parent) agent did not invoke a tool by name.
     *
     * Useful for encapsulation checks when sub-agents own internal tools.
     *
     * @param string $name Tool name
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertParentDidNotInvokeTool(string $name, string $message = ''): void
    {
        $parentClass = EventCapture::rootAgentClass();
        Assert::assertNotNull(
            $parentClass,
            ($message !== '' ? $message . "\n" : '') . 'No parent agent was recorded.' . "\n" . EventCapture::timeline(),
        );

        $invokedByParent = array_any(
            EventCapture::tools(),
            fn(RecordedToolInvocation $tool): bool => $tool->name === $name
                && $tool->agentClass === $parentClass,
        );

        Assert::assertFalse(
            $invokedByParent,
            ($message !== '' ? $message . "\n" : '')
                . sprintf('Expected parent [%s] not to invoke tool [%s].', $parentClass, $name)
                . "\n" . EventCapture::timeline(),
        );
    }

    /**
     * Assert the last response message count.
     *
     * @param int $count Expected message count
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertConversationMessageCount(int $count, string $message = ''): void
    {
        $actual = count(EventCapture::conversationMessages());

        Assert::assertSame(
            $count,
            $actual,
            ($message !== '' ? $message . "\n" : '')
                . sprintf('Expected %d conversation messages, got %d.', $count, $actual)
                . "\n" . EventCapture::timeline(),
        );
    }

    /**
     * Assert the last response contains a message with the given role.
     *
     * @param \Crustum\Ai\Messages\MessageRole|string $role Message role
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertConversationContainsRole(
        MessageRole|string $role,
        string $message = '',
    ): void {
        $expected = $role instanceof MessageRole ? $role : MessageRole::from($role);
        $found = array_any(
            EventCapture::conversationMessages(),
            fn(Message $msg): bool => $msg->role === $expected,
        );

        Assert::assertTrue(
            $found,
            ($message !== '' ? $message . "\n" : '')
                . sprintf('Expected conversation to contain role [%s].', $expected->value)
                . "\n" . EventCapture::timeline(),
        );
    }

    /**
     * Assert recorded responses share one non-empty conversation id across prompts.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public static function assertRememberedAcrossPrompts(string $message = ''): void
    {
        $ids = EventCapture::conversationIds();
        Assert::assertNotEmpty(
            $ids,
            ($message !== '' ? $message . "\n" : '')
                . 'Expected conversation IDs on responses.'
                . "\n" . EventCapture::timeline(),
        );
        Assert::assertGreaterThanOrEqual(
            2,
            count($ids),
            ($message !== '' ? $message . "\n" : '')
                . 'Expected at least two remembered responses.'
                . "\n" . EventCapture::timeline(),
        );
        Assert::assertSame(
            array_fill(0, count($ids), $ids[0]),
            $ids,
            ($message !== '' ? $message . "\n" : '')
                . 'Expected the same conversation ID across prompts.'
                . "\n" . EventCapture::timeline(),
        );
    }
}
