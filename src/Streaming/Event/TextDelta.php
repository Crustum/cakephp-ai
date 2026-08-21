<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Cake\Collection\Collection;

/**
 * Text delta event.
 *
 * Represents an incremental chunk of text in a streaming response.
 */
class TextDelta extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $messageId Message ID
     * @param string $delta Text delta chunk
     * @param int $timestamp Unix timestamp
     */
    public function __construct(
        public string $id,
        public string $messageId,
        public string $delta,
        public int $timestamp,
    ) {
    }

    /**
     * Combine the text deltas in the given collection of events into a single string.
     *
     * Deltas from a multi-step generation carry a distinct message ID per step,
     * and each step's text is a self-contained utterance (typically narration
     * around a tool call). Steps are therefore joined with a blank line instead
     * of being run together mid-sentence.
     *
     * @param \Cake\Collection\Collection<\Crustum\Ai\Streaming\Event\StreamEvent>|array<\Crustum\Ai\Streaming\Event\StreamEvent> $events Events
     * @return string
     */
    public static function combine(Collection|array $events): string
    {
        $events = is_array($events) ? collection($events) : $events;

        $steps = $events->filter(fn($event): bool => $event instanceof TextDelta)
            ->groupBy(fn(TextDelta $event): string => $event->messageId)
            ->map(function (array $deltas): string {
                $text = '';
                foreach ($deltas as $event) {
                    $text .= $event->delta;
                }

                return $text;
            })
            ->filter(fn(string $text): bool => trim($text) !== '')
            ->toList();

        return implode("\n\n", $steps);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'text_delta',
            'message_id' => $this->messageId,
            'delta' => $this->delta,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @inheritDoc
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'text-delta',
            'id' => $this->messageId,
            'delta' => $this->delta,
        ];
    }
}
