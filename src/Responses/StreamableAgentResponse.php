<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\CollectionInterface;
use Cake\Http\Response;
use Closure;
use Crustum\Ai\Http\Stream\EventStreamResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Streaming\Event\Citation;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Crustum\Ai\Streaming\Protocols\StreamProtocol;
use Crustum\Ai\Streaming\Protocols\VercelDataProtocol;
use IteratorAggregate;
use Throwable;
use Traversable;

/**
 * Streamable Agent Response
 *
 * Represents an agent response that can be streamed.
 *
 * @implements \IteratorAggregate<int, \Crustum\Ai\Streaming\Event\StreamEvent>
 */
class StreamableAgentResponse implements IteratorAggregate
{
    /**
     * Stream protocol used for the HTTP response.
     */
    protected ?StreamProtocol $protocol = null;

    /**
     * Generated text (after streaming)
     */
    public ?string $text = null;

    /**
     * Token usage (after streaming)
     */
    public ?TextUsage $usage = null;

    /**
     * Stream events
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    public CollectionInterface $events;

    /**
     * Cited sources (after streaming)
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\Citation>
     */
    public CollectionInterface $citations;

    /**
     * Conversation identifier
     */
    public ?string $conversationId = null;

    /**
     * Conversation user/participant
     */
    public ?object $conversationUser = null;

    /**
     * Persisted user message row this turn wrote, if any.
     */
    public ?string $userMessageId = null;

    /**
     * Persisted assistant message row this turn wrote, if any.
     */
    public ?string $assistantMessageId = null;

    /**
     * Reasoning the streamed turn produced, if any.
     */
    public string $reasoning = '';

    /**
     * Callbacks to execute after streaming completes
     *
     * @var array<int, callable>
     */
    protected array $thenCallbacks = [];

    /**
     * Callbacks to execute when streaming fails
     *
     * @var array<int, callable>
     */
    protected array $catchCallbacks = [];

    /**
     * Completed streamed response
     */
    protected ?StreamedAgentResponse $streamedResponse = null;

    /**
     * Whether the response has handed at least one event to a consumer.
     */
    protected bool $hasYielded = false;

    /**
     * Constructor
     *
     * @param string $invocationId The unique invocation identifier
     * @param \Closure $generator Generator function for stream events
     * @param \Crustum\Ai\Responses\Data\Meta|null $meta Metadata about the response
     */
    public function __construct(
        public string $invocationId,
        protected Closure $generator,
        protected ?Meta $meta = null,
    ) {
        $this->events = collection([]);
        $this->citations = collection([]);
    }

    /**
     * Execute a callback over each event.
     *
     * @param callable $callback The callback to execute for each event
     */
    public function each(callable $callback): static
    {
        foreach ($this as $event) {
            if ($callback($event) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Provide a callback that should be invoked when the stream fails.
     *
     * @param callable $callback The callback to execute
     */
    public function catch(callable $callback): static
    {
        $this->catchCallbacks[] = $callback;

        return $this;
    }

    /**
     * Provide a callback that should be invoked when the stream completes.
     *
     * @param callable $callback The callback to execute
     */
    public function then(callable $callback): static
    {
        if ($this->streamedResponse instanceof StreamedAgentResponse) {
            $callback($this->streamedResponse);

            $this->syncConversationFromStreamedResponse();

            return $this;
        }

        $this->thenCallbacks[] = $callback;

        return $this;
    }

    /**
     * Set the conversation UUID for this response.
     *
     * @param string|null $conversationId The conversation identifier
     * @param object|null $conversationUser The conversation user/participant
     */
    public function withinConversation(?string $conversationId, ?object $conversationUser = null): static
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $conversationUser;

        return $this;
    }

    /**
     * Adopt state from a completed streamed response.
     *
     * @param \Crustum\Ai\Responses\StreamedAgentResponse $response The streamed response
     */
    public function adoptStateFrom(StreamedAgentResponse $response): static
    {
        if ($this->meta instanceof Meta) {
            $this->meta->provider = $response->meta->provider;
            $this->meta->model = $response->meta->model;
            $this->meta->citations = $response->meta->citations;
        }

        if ($response->conversationId !== null) {
            $this->withinConversation($response->conversationId, $response->conversationUser);
        }

        $this->userMessageId = $response->userMessageId;
        $this->assistantMessageId = $response->assistantMessageId;

        return $this;
    }

    /**
     * Stream the response using the given stream protocol.
     *
     * @param \Crustum\Ai\Streaming\Protocols\StreamProtocol $protocol Stream protocol
     */
    public function usingProtocol(StreamProtocol $protocol): static
    {
        $this->protocol = $protocol;

        return $this;
    }

    /**
     * Stream the response using the Vercel AI SDK data stream protocol.
     *
     * The message ID is the assistant message being continued, not the "messageId" sent by useChat.
     *
     * @param string|null $messageId Client message id to continue
     */
    public function usingVercelDataProtocol(?string $messageId = null): static
    {
        return $this->usingProtocol(new VercelDataProtocol($messageId));
    }

    /**
     * Stream the response using the Agent User Interaction protocol.
     *
     * @param string|null $threadId Thread identifier
     * @param string|null $runId Run identifier
     */
    public function usingAgentUserInteractionProtocol(?string $threadId = null, ?string $runId = null): static
    {
        return $this->usingProtocol(new AgentUserInteractionProtocol($threadId, $runId));
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @return \Cake\Http\Response
     */
    public function toResponse(): Response
    {
        if ($this->protocol instanceof StreamProtocol) {
            return $this->protocol->response($this);
        }

        return new EventStreamResponse($this);
    }

    /**
     * Get an iterator for the object.
     *
     * @return \Traversable<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    public function getIterator(): Traversable
    {
        // Use existing events if we've already streamed them once...
        if (count($this->events) > 0) {
            foreach ($this->events as $event) {
                $this->hasYielded = true;

                yield $event;
            }

            return;
        }

        /** @var array<int, \Crustum\Ai\Streaming\Event\StreamEvent> $events */
        $events = [];

        // Resolve the stream of the prompt and yield the events...
        try {
            foreach (call_user_func($this->generator) as $event) {
                $events[] = $event;

                $this->hasYielded = true;

                yield $event;
            }
        } catch (Throwable $throwable) {
            // Taken before invoking so a re-iterated stream does not report the same failure twice.
            $callbacks = $this->catchCallbacks;

            $this->catchCallbacks = [];

            foreach ($callbacks as $callback) {
                $callback($throwable);
            }

            throw $throwable;
        }

        $this->events = collection($events);
        $this->text = TextDelta::combine($events);
        $this->reasoning = ReasoningDelta::combine($events);
        $this->citations = Citation::combine($events);
        $this->usage = StreamEnd::combineUsage($events);

        $start = null;

        foreach ($events as $event) {
            if ($event instanceof StreamStart) {
                $start = $event;
            }
        }

        if ($start instanceof StreamStart && $this->meta instanceof Meta) {
            $this->meta->model = $start->model;
        }

        $this->streamedResponse = new StreamedAgentResponse(
            $this->invocationId,
            $this->events,
            $this->meta,
        );

        if ($this->conversationId !== null) {
            $this->streamedResponse->withinConversation(
                $this->conversationId,
                $this->conversationUser,
            );
        }

        $this->streamedResponse->withStoredMessages(
            $this->userMessageId,
            $this->assistantMessageId,
        );

        foreach ($this->thenCallbacks as $callback) {
            call_user_func($callback, $this->streamedResponse);
        }

        $this->syncConversationFromStreamedResponse();
    }

    /**
     * Sync conversation data from the streamed response
     *
     * @return void
     */
    protected function syncConversationFromStreamedResponse(): void
    {
        $this->conversationId = $this->streamedResponse->conversationId;
        $this->conversationUser = $this->streamedResponse->conversationUser;
        $this->userMessageId = $this->streamedResponse->userMessageId;
        $this->assistantMessageId = $this->streamedResponse->assistantMessageId;
    }

    /**
     * Determine whether this response has handed at least one event to a consumer.
     *
     * @return bool
     */
    public function hasYielded(): bool
    {
        return $this->hasYielded;
    }
}
