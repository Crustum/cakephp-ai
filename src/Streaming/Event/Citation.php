<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Crustum\Ai\Responses\Data\Citation as CitationData;
use Crustum\Ai\Responses\Data\UrlCitation;
use UnhandledMatchError;

/**
 * Citation event.
 *
 * Represents a citation or source reference in a streaming response.
 */
class Citation extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $messageId Message ID
     * @param \Crustum\Ai\Responses\Data\Citation $citation Citation data
     * @param int $timestamp Unix timestamp
     */
    public function __construct(
        public string $id,
        public string $messageId,
        public CitationData $citation,
        public int $timestamp,
    ) {
    }

    /**
     * Combine citation events into the sources the run cited, in the order it cited them.
     *
     * @param \Cake\Collection\Collection<int, \Crustum\Ai\Streaming\Event\StreamEvent>|array<\Crustum\Ai\Streaming\Event\StreamEvent> $events Events
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\Citation>
     */
    public static function combine(Collection|array $events): CollectionInterface
    {
        $events = is_array($events) ? collection($events) : $events;

        /** @var array<int, \Crustum\Ai\Responses\Data\Citation> $citations */
        $citations = [];

        foreach ($events as $event) {
            if ($event instanceof Citation) {
                $citations[] = $event->citation;
            }
        }

        return collection($citations);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'citation',
            'message_id' => $this->messageId,
            'citation' => match (true) {
                $this->citation instanceof UrlCitation => [
                    'title' => $this->citation->title,
                    'url' => $this->citation->url,
                ],
                default => throw new UnhandledMatchError(
                    'Citation serialization supports UrlCitation only; got ' . $this->citation::class,
                ),
            },
            'timestamp' => $this->timestamp,
        ];
    }
}
