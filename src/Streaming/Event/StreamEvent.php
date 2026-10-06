<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Cake\Log\Log;
use Crustum\Broadcasting\Broadcasting;
use Crustum\Broadcasting\Channel\Channel;
use Crustum\Broadcasting\Exception\BroadcastingException;
use Stringable;

/**
 * Base class for streaming events.
 *
 * Provides broadcasting capabilities and serialization for real-time
 * streaming of AI responses. Supports CakePHP Broadcasting and
 * stream protocols (Vercel data stream, Agent User Interaction).
 */
abstract class StreamEvent implements Stringable
{
    /**
     * The invocation ID associated with the event.
     */
    public ?string $invocationId = null;

    /**
     * Get the array representation of the event.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * Broadcast the stream event using the queue.
     *
     * @param \Crustum\Broadcasting\Channel\Channel|array<\Crustum\Broadcasting\Channel\Channel> $channels The channels to broadcast on
     * @param bool $now Whether to broadcast immediately
     * @return void
     */
    public function broadcast(Channel|array $channels, bool $now = false): void
    {
        try {
            $broadcast = Broadcasting::to($channels)
                ->event($this->type())
                ->data($this->toArray());

            if ($now) {
                $broadcast->send();
            } else {
                $broadcast->queue();
            }
        } catch (BroadcastingException $broadcastingException) {
            Log::error('Broadcasting failed: ' . $broadcastingException->getMessage());
        }
    }

    /**
     * Broadcast the stream event immediately.
     *
     * @param \Crustum\Broadcasting\Channel\Channel|array<\Crustum\Broadcasting\Channel\Channel> $channels The channels to broadcast on
     * @return void
     */
    public function broadcastNow(Channel|array $channels): void
    {
        $this->broadcast($channels, now: true);
    }

    /**
     * Get the event's type.
     *
     * @return string
     */
    public function type(): string
    {
        return $this->toArray()['type'];
    }

    /**
     * Set the invocation ID associated with the event.
     *
     * @param string $id The invocation ID
     * @return $this
     */
    public function withInvocationId(string $id)
    {
        $this->invocationId = $id;

        return $this;
    }

    /**
     * Get the string representation of the event.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string)json_encode($this->toArray());
    }
}
