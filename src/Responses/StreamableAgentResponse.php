<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\CollectionInterface;
use Cake\Http\Response;
use Closure;
use Crustum\Ai\Http\Stream\EventStreamResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use IteratorAggregate;
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
    use Trait\CanStreamUsingVercelProtocolTrait;

    /**
     * Generated text (after streaming)
     */
    public ?string $text = null;

    /**
     * Token usage (after streaming)
     */
    public ?Usage $usage = null;

    /**
     * Stream events
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    public CollectionInterface $events;

    /**
     * Conversation identifier
     */
    public ?string $conversationId = null;

    /**
     * Conversation user/participant
     */
    public ?object $conversationUser = null;

    /**
     * Callbacks to execute after streaming completes
     *
     * @var array<int, callable>
     */
    protected array $thenCallbacks = [];

    /**
     * Whether to use Vercel protocol for streaming
     */
    protected bool $usesVercelProtocol = false;

    /**
     * Client message id to continue when streaming the Vercel protocol
     */
    protected ?string $vercelProtocolMessageId = null;

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

        return $this;
    }

    /**
     * Stream the response using Vercel's AI SDK stream protocol.
     *
     * See: https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
     *
     * @param bool $value Whether to use Vercel protocol
     * @param string|null $messageId Client message id to continue
     */
    public function usingVercelDataProtocol(bool $value = true, ?string $messageId = null): static
    {
        $this->usesVercelProtocol = $value;
        $this->vercelProtocolMessageId = $messageId;

        return $this;
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @return \Cake\Http\Response
     */
    public function toResponse(): Response
    {
        if ($this->usesVercelProtocol) {
            return $this->toVercelProtocolResponse();
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
        if (!$this->events->isEmpty()) {
            foreach ($this->events as $event) {
                $this->hasYielded = true;

                yield $event;
            }

            return;
        }

        /** @var array<int, \Crustum\Ai\Streaming\Event\StreamEvent> $events */
        $events = [];

        // Resolve the stream of the prompt and yield the events...
        foreach (call_user_func($this->generator) as $event) {
            $events[] = $event;

            $this->hasYielded = true;

            yield $event;
        }

        $this->events = collection($events);
        $this->text = TextDelta::combine($events);
        $this->usage = StreamEnd::combineUsage($events);

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
        if ($this->streamedResponse->conversationId === null) {
            return;
        }

        $this->conversationId = $this->streamedResponse->conversationId;
        $this->conversationUser = $this->streamedResponse->conversationUser;
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
