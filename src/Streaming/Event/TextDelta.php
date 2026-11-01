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
     * Combine the text deltas, starting a new paragraph only after a step that ran tools.
     *
     * @param \Cake\Collection\Collection<int, \Crustum\Ai\Streaming\Event\StreamEvent>|array<\Crustum\Ai\Streaming\Event\StreamEvent> $events Events
     * @return string
     */
    public static function combine(Collection|array $events): string
    {
        $events = is_array($events) ? collection($events) : $events;

        $steps = [];
        $current = [];
        $currentHasToolResult = false;

        foreach ($events as $event) {
            if ($event instanceof StreamStart && $current !== [] && $currentHasToolResult) {
                $steps[] = $current;
                $current = [];
                $currentHasToolResult = false;
            }

            if ($event instanceof ToolResult) {
                $currentHasToolResult = true;
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

        return static::join($texts);
    }

    /**
     * Join the text of each step, separating the steps with a blank line.
     *
     * @param \Cake\Collection\Collection<array-key, string>|array<array-key, string> $texts Step texts
     * @return string
     */
    public static function join(Collection|array $texts): string
    {
        $texts = is_array($texts) ? collection($texts) : $texts;

        return $texts->filter(fn(string $text): bool => trim($text) !== '')->implode("\n\n");
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
