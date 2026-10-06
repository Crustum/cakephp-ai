<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Capture;

use Cake\Event\EventManager;
use Crustum\Ai\Event\AddingFileToStore;
use Crustum\Ai\Event\AgentFailed;
use Crustum\Ai\Event\AgentFailedOver;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\AgentStreamed;
use Crustum\Ai\Event\AudioGenerated;
use Crustum\Ai\Event\CreatingStore;
use Crustum\Ai\Event\EmbeddingsGenerated;
use Crustum\Ai\Event\FileAddedToStore;
use Crustum\Ai\Event\FileDeleted;
use Crustum\Ai\Event\FileRemovedFromStore;
use Crustum\Ai\Event\FileStored;
use Crustum\Ai\Event\GeneratingAudio;
use Crustum\Ai\Event\GeneratingEmbeddings;
use Crustum\Ai\Event\GeneratingImage;
use Crustum\Ai\Event\GeneratingTranscription;
use Crustum\Ai\Event\ImageGenerated;
use Crustum\Ai\Event\InvokingTool;
use Crustum\Ai\Event\PromptingAgent;
use Crustum\Ai\Event\ProviderFailedOver;
use Crustum\Ai\Event\RemovingFileFromStore;
use Crustum\Ai\Event\Reranked;
use Crustum\Ai\Event\Reranking;
use Crustum\Ai\Event\StartingStep;
use Crustum\Ai\Event\StepCompleted;
use Crustum\Ai\Event\StepFailed;
use Crustum\Ai\Event\StoreCreated;
use Crustum\Ai\Event\StoreDeleted;
use Crustum\Ai\Event\StoringFile;
use Crustum\Ai\Event\StreamingAgent;
use Crustum\Ai\Event\ToolApprovalRequested;
use Crustum\Ai\Event\ToolApprovalResolved;
use Crustum\Ai\Event\ToolFailed;
use Crustum\Ai\Event\ToolInvoked;
use Crustum\Ai\Event\TranscriptionGenerated;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Responses\TextResponse;
use Crustum\Ai\TestSuite\Tool\RecordedToolInvocation;
use InvalidArgumentException;

/**
 * Captures AI domain events, tool invocations, and response steps.
 */
class EventCapture
{
    /**
     * Default event classes captured for agentic flow assertions.
     *
     * @var list<class-string>
     */
    public const DEFAULT_EVENTS = [
        PromptingAgent::class,
        AgentPrompted::class,
        StreamingAgent::class,
        AgentStreamed::class,
        StartingStep::class,
        StepCompleted::class,
        StepFailed::class,
        InvokingTool::class,
        ToolInvoked::class,
        ToolFailed::class,
        ToolApprovalRequested::class,
        ToolApprovalResolved::class,
        AgentFailed::class,
        AgentFailedOver::class,
        ProviderFailedOver::class,
        GeneratingEmbeddings::class,
        EmbeddingsGenerated::class,
        GeneratingImage::class,
        ImageGenerated::class,
        GeneratingAudio::class,
        AudioGenerated::class,
        GeneratingTranscription::class,
        TranscriptionGenerated::class,
        Reranking::class,
        Reranked::class,
        StoringFile::class,
        FileStored::class,
        FileDeleted::class,
        CreatingStore::class,
        StoreCreated::class,
        StoreDeleted::class,
        AddingFileToStore::class,
        FileAddedToStore::class,
        RemovingFileFromStore::class,
        FileRemovedFromStore::class,
    ];

    /**
     * @var array<int, object>
     */
    protected static array $events = [];

    /**
     * @var array<int, \Crustum\Ai\TestSuite\Tool\RecordedToolInvocation>
     */
    protected static array $tools = [];

    /**
     * @var array<int, \Crustum\Ai\Responses\Data\Step>
     */
    protected static array $steps = [];

    /**
     * @var array<int, \Crustum\Ai\Responses\TextResponse|\Crustum\Ai\Responses\AgentResponse|\Crustum\Ai\Responses\StreamedAgentResponse>
     */
    protected static array $responses = [];

    /**
     * @var array<int, string>
     */
    protected static array $conversationIds = [];

    /**
     * @var list<class-string>
     */
    protected static array $eventClasses = [];

    /**
     * @var array<string, callable>
     */
    protected static array $listeners = [];

    /**
     * Event manager instance listeners are currently bound to.
     *
     * @var \Cake\Event\EventManager|null
     */
    protected static ?EventManager $boundManager = null;

    /**
     * Start capturing the given AI event classes.
     *
     * @param list<class-string>|null $eventClasses Event classes
     * @return void
     */
    public static function start(?array $eventClasses = null): void
    {
        self::stop();

        self::$eventClasses = $eventClasses ?? self::DEFAULT_EVENTS;
        $manager = EventManager::instance();

        foreach (self::$eventClasses as $eventClass) {
            $eventName = self::eventNameFor($eventClass);
            $listener = static function (mixed $event) use ($eventClass): void {
                if (!$event instanceof $eventClass) {
                    return;
                }

                self::$events[] = $event;
                self::recordDerivedState($event);
            };

            self::$listeners[$eventName] = $listener;
            $manager->on($eventName, $listener);
        }

        self::$boundManager = $manager;
    }

    /**
     * Rebind listeners when Cake TestCase replaced EventManager::instance().
     *
     * Does not clear recorded events/tools/steps.
     *
     * @return void
     */
    public static function ensureListening(): void
    {
        if (self::$listeners !== [] && self::$boundManager === EventManager::instance()) {
            return;
        }

        $classes = self::$eventClasses !== [] ? self::$eventClasses : null;
        self::start($classes);
    }

    /**
     * Stop capturing and clear listeners without clearing recorded data.
     *
     * @return void
     */
    public static function stop(): void
    {
        $manager = self::$boundManager ?? EventManager::instance();

        foreach (self::$listeners as $eventName => $listener) {
            $manager->off($eventName, $listener);
        }

        self::$listeners = [];
        self::$boundManager = null;
    }

    /**
     * Reset recorded data and restart capture with the current/default event set.
     *
     * @param list<class-string>|null $eventClasses Event classes
     * @return void
     */
    public static function reset(?array $eventClasses = null): void
    {
        $classes = $eventClasses ?? (self::$eventClasses !== [] ? self::$eventClasses : null);

        self::stop();
        self::$eventClasses = [];
        self::$events = [];
        self::$tools = [];
        self::$steps = [];
        self::$responses = [];
        self::$conversationIds = [];
        self::start($classes);
    }

    /**
     * Replace the captured event class allow-list and restart listening.
     *
     * @param list<class-string> $eventClasses Event classes
     * @return void
     */
    public static function capture(array $eventClasses): void
    {
        self::reset($eventClasses);
    }

    /**
     * Manually observe a response for step and usage assertions.
     *
     * @param \Crustum\Ai\Responses\TextResponse|\Crustum\Ai\Responses\AgentResponse|\Crustum\Ai\Responses\StreamedAgentResponse $response Response
     * @return void
     */
    public static function observe(TextResponse|AgentResponse|StreamedAgentResponse $response): void
    {
        self::$responses[] = $response;
        self::recordStepsFromResponse($response);
        self::recordStreamEventsFromResponse($response);
        self::recordConversationIdFromResponse($response);
    }

    /**
     * Observe a streamable response by consuming it and recording stream events.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Crustum\Ai\Responses\StreamableAgentResponse
     */
    public static function observeStream(StreamableAgentResponse $response): StreamableAgentResponse
    {
        foreach ($response as $event) {
            StreamCapture::record($event);
        }

        return $response;
    }

    /**
     * @return array<int, object>
     */
    public static function events(): array
    {
        return self::$events;
    }

    /**
     * @param class-string $eventClass Event class
     * @return array<int, object>
     */
    public static function eventsOf(string $eventClass): array
    {
        return array_values(array_filter(
            self::$events,
            fn(object $event): bool => $event instanceof $eventClass,
        ));
    }

    /**
     * @return array<int, \Crustum\Ai\TestSuite\Tool\RecordedToolInvocation>
     */
    public static function tools(): array
    {
        return self::$tools;
    }

    /**
     * @return array<int, \Crustum\Ai\Responses\Data\Step>
     */
    public static function steps(): array
    {
        return self::$steps;
    }

    /**
     * @return array<int, \Crustum\Ai\Responses\TextResponse|\Crustum\Ai\Responses\AgentResponse|\Crustum\Ai\Responses\StreamedAgentResponse>
     */
    public static function responses(): array
    {
        return self::$responses;
    }

    /**
     * Get the most recently observed response.
     *
     * @return \Crustum\Ai\Responses\TextResponse|\Crustum\Ai\Responses\AgentResponse|\Crustum\Ai\Responses\StreamedAgentResponse|null
     */
    public static function lastResponse(): TextResponse|AgentResponse|StreamedAgentResponse|null
    {
        if (self::$responses === []) {
            return null;
        }

        return self::$responses[array_key_last(self::$responses)];
    }

    /**
     * Get recorded conversation identifiers from responses.
     *
     * @return array<int, string>
     */
    public static function conversationIds(): array
    {
        return self::$conversationIds;
    }

    /**
     * Get messages from the last agent response when present.
     *
     * @return array<int, \Crustum\Ai\Messages\Message>
     */
    public static function conversationMessages(): array
    {
        $response = self::lastResponse();
        if (!$response instanceof TextResponse) {
            return [];
        }

        return $response->messages->toList();
    }

    /**
     * Resolve the first prompted agent class in this capture, if any.
     *
     * @return class-string<\Crustum\Ai\Contracts\Agent>|null
     */
    public static function rootAgentClass(): ?string
    {
        foreach (self::$events as $event) {
            if ($event instanceof PromptingAgent) {
                return $event->prompt->agent::class;
            }

            if ($event instanceof AgentPrompted) {
                return $event->prompt->agent::class;
            }
        }

        return null;
    }

    /**
     * Timeline summary for assertion failures.
     *
     * @return string
     */
    public static function timeline(): string
    {
        $lines = [];

        if (self::$events !== []) {
            $lines[] = 'Events (' . count(self::$events) . '):';
            foreach (self::$events as $index => $event) {
                $lines[] = '  [' . $index . '] ' . $event::class;
            }
        }

        if (self::$tools !== []) {
            $lines[] = 'Tools (' . count(self::$tools) . '):';
            foreach (self::$tools as $index => $tool) {
                $lines[] = '  [' . $index . '] ' . $tool->summary();
            }
        }

        if (self::$steps !== []) {
            $lines[] = 'Steps (' . count(self::$steps) . '):';
            foreach (self::$steps as $index => $step) {
                $lines[] = sprintf(
                    '  [%d] finish=%s tools=%d',
                    $index,
                    $step->finishReason->value,
                    count($step->toolCalls),
                );
            }
        }

        if (self::$conversationIds !== []) {
            $lines[] = 'Conversation IDs: ' . implode(', ', self::$conversationIds);
        }

        $streamTimeline = StreamCapture::timeline();
        if (!str_contains($streamTimeline, '(0): none')) {
            $lines[] = $streamTimeline;
        }

        if ($lines === []) {
            return 'Recorded events/tools/steps (0): none';
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve the Cake event name for an event class.
     *
     * @param class-string $eventClass Event class
     * @return string
     */
    protected static function eventNameFor(string $eventClass): string
    {
        if (is_callable([$eventClass, 'eventName'])) {
            return $eventClass::eventName();
        }

        throw new InvalidArgumentException(
            sprintf('Event class [%s] must define eventName().', $eventClass),
        );
    }

    /**
     * Record derived tool/step/response state from an event.
     *
     * @param object $event Captured event
     * @return void
     */
    protected static function recordDerivedState(object $event): void
    {
        if ($event instanceof ToolInvoked) {
            self::$tools[] = RecordedToolInvocation::fromEvent($event);
        }

        if ($event instanceof AgentPrompted) {
            self::$responses[] = $event->response;
            self::recordStepsFromResponse($event->response);
            self::recordStreamEventsFromResponse($event->response);
            self::recordConversationIdFromResponse($event->response);
        }
    }

    /**
     * Record steps from a response when present.
     *
     * @param \Crustum\Ai\Responses\TextResponse|\Crustum\Ai\Responses\AgentResponse|\Crustum\Ai\Responses\StreamedAgentResponse $response Response
     * @return void
     */
    protected static function recordStepsFromResponse(
        TextResponse|AgentResponse|StreamedAgentResponse $response,
    ): void {
        if (!isset($response->steps)) {
            return;
        }

        foreach ($response->steps as $step) {
            self::$steps[] = $step;
        }
    }

    /**
     * Record stream events from a streamed agent response when present.
     *
     * @param \Crustum\Ai\Responses\TextResponse|\Crustum\Ai\Responses\AgentResponse|\Crustum\Ai\Responses\StreamedAgentResponse $response Response
     * @return void
     */
    protected static function recordStreamEventsFromResponse(
        TextResponse|AgentResponse|StreamedAgentResponse $response,
    ): void {
        if (!$response instanceof StreamedAgentResponse) {
            return;
        }

        StreamCapture::replace($response->events);
    }

    /**
     * Record a conversation identifier from a response when present.
     *
     * @param \Crustum\Ai\Responses\TextResponse|\Crustum\Ai\Responses\AgentResponse|\Crustum\Ai\Responses\StreamedAgentResponse $response Response
     * @return void
     */
    protected static function recordConversationIdFromResponse(
        TextResponse|AgentResponse|StreamedAgentResponse $response,
    ): void {
        if (!$response instanceof AgentResponse) {
            return;
        }

        if (is_string($response->conversationId) && $response->conversationId !== '') {
            self::$conversationIds[] = $response->conversationId;
        }
    }
}
