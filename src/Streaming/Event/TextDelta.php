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
     * Each step of a multi-step generation is a self-contained utterance
     * (typically narration around a tool call), so steps are joined with a blank
     * line instead of being run together mid-sentence. The boundary is the step's
     * own `StreamStart` rather than a change of message ID, which Anthropic rotates
     * per content block — web search splits one answer across several, mid-sentence.
     *
     * @param \Cake\Collection\Collection<int, \Crustum\Ai\Streaming\Event\StreamEvent>|array<\Crustum\Ai\Streaming\Event\StreamEvent> $events Events
     * @return string
     */
    public static function combine(Collection|array $events): string
    {
        $events = is_array($events) ? collection($events) : $events;

        $steps = [];
        $current = [];

        foreach ($events as $event) {
            if ($event instanceof StreamStart && $current !== []) {
                $steps[] = $current;
                $current = [];
            }

            $current[] = $event;
        }

        if ($current !== []) {
            $steps[] = $current;
        }

        $texts = [];

        foreach ($steps as $step) {
            $text = '';

            foreach ($step as $event) {
                if ($event instanceof TextDelta) {
                    $text .= $event->delta;
                }
            }

            if (trim($text) !== '') {
                $texts[] = $text;
            }
        }

        return implode("\n\n", $texts);
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
}
