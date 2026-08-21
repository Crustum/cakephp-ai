<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;
use Crustum\Ai\Streaming\Event\StreamEvent;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Attribute to exclude specific stream events from broadcasting.
 *
 * Allows agents to specify which streaming events should not be
 * broadcast to clients, useful for filtering sensitive or unnecessary events.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WithoutBroadcasting
{
    /**
     * The stream event classes that should not be broadcast.
     *
     * @var array<int, class-string<\Crustum\Ai\Streaming\Event\StreamEvent>>
     */
    public array $events;

    /**
     * Create a new attribute instance.
     *
     * @param class-string<\Crustum\Ai\Streaming\Event\StreamEvent> ...$events Event classes to exclude
     * @throws \InvalidArgumentException if any event is not a valid StreamEvent
     */
    public function __construct(string ...$events)
    {
        foreach ($events as $event) {
            if (!is_subclass_of($event, StreamEvent::class)) {
                throw new InvalidArgumentException(sprintf('[%s] is not a valid ', $event) . StreamEvent::class . ' to exclude from broadcasting.');
            }
        }

        $this->events = $events;
    }

    /**
     * Determine if the given event is excluded by the resolved skip set.
     *
     * @param array<int, class-string<\Crustum\Ai\Streaming\Event\StreamEvent>> $events Events to check
     * @param \Crustum\Ai\Streaming\Event\StreamEvent $event Event instance
     * @return bool
     */
    public static function excludes(array $events, StreamEvent $event): bool
    {
        return in_array($event::class, $events, true);
    }

    /**
     * Get the stream event classes that should not be broadcast for the target agent.
     *
     * @param object|null $target The target agent
     * @return array<int, class-string<\Crustum\Ai\Streaming\Event\StreamEvent>>
     */
    public static function eventsFor(?object $target): array
    {
        if ($target === null) {
            return [];
        }

        $attributes = (new ReflectionClass($target))->getAttributes(self::class);

        if ($attributes === []) {
            return [];
        }

        return $attributes[0]->newInstance()->events;
    }
}
