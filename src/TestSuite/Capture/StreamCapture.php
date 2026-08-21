<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Capture;

use Crustum\Ai\Streaming\Event\StreamEvent;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolCall;

/**
 * Captures streaming events for agentic flow assertions.
 */
class StreamCapture
{
    /**
     * @var array<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    protected static array $events = [];

    /**
     * Reset recorded stream events.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$events = [];
    }

    /**
     * Record a stream event.
     *
     * @param \Crustum\Ai\Streaming\Event\StreamEvent $event Stream event
     * @return void
     */
    public static function record(StreamEvent $event): void
    {
        self::$events[] = $event;
    }

    /**
     * Replace recorded stream events with the given set.
     *
     * @param iterable<\Crustum\Ai\Streaming\Event\StreamEvent> $events Stream events
     * @return void
     */
    public static function replace(iterable $events): void
    {
        self::$events = [];
        self::recordMany($events);
    }

    /**
     * Record many stream events.
     *
     * @param iterable<\Crustum\Ai\Streaming\Event\StreamEvent> $events Stream events
     * @return void
     */
    public static function recordMany(iterable $events): void
    {
        foreach ($events as $event) {
            self::record($event);
        }
    }

    /**
     * @return array<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    public static function events(): array
    {
        return self::$events;
    }

    /**
     * @param class-string<\Crustum\Ai\Streaming\Event\StreamEvent> $eventClass Event class
     * @return array<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    public static function eventsOf(string $eventClass): array
    {
        return array_values(array_filter(
            self::$events,
            fn(StreamEvent $event): bool => $event instanceof $eventClass,
        ));
    }

    /**
     * Concatenate recorded text deltas.
     *
     * @return string
     */
    public static function combinedText(): string
    {
        return TextDelta::combine(self::$events);
    }

    /**
     * Whether a stream tool call with the given name was emitted.
     *
     * @param string $name Tool name
     * @return bool
     */
    public static function hasToolCall(string $name): bool
    {
        return array_any(
            self::$events,
            fn(StreamEvent $event): bool => $event instanceof ToolCall
                && $event->toolCall->name === $name,
        );
    }

    /**
     * Timeline summary for assertion failures.
     *
     * @return string
     */
    public static function timeline(): string
    {
        if (self::$events === []) {
            return 'Recorded stream events (0): none';
        }

        $lines = ['Stream events (' . count(self::$events) . '):'];

        foreach (self::$events as $index => $event) {
            $summary = $event::class;
            if ($event instanceof TextDelta) {
                $summary .= ' delta=' . json_encode($event->delta);
            }

            if ($event instanceof ToolCall) {
                $summary .= ' tool=' . $event->toolCall->name;
            }

            $lines[] = '  [' . $index . '] ' . $summary;
        }

        return implode("\n", $lines);
    }
}
