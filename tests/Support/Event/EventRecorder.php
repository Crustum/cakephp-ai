<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Event;

use Cake\Event\EventManager;

/**
 * Event recorder for testing AI event dispatching.
 *
 * @deprecated 1.x Prefer `AiFlowTrait` / `AiFlow::assertAiEventDispatched()` (auto-capture).
 *             Remaining call sites may keep this helper until migrated.
 */
class EventRecorder
{
    /**
     * Recorded events.
     *
     * @var array<int, object>
     */
    public array $events = [];

    /**
     * Start recording events.
     *
     * @param array<int, class-string> $eventClasses Event classes to record.
     */
    public static function start(array $eventClasses): self
    {
        $recorder = new self();

        foreach ($eventClasses as $eventClass) {
            EventManager::instance()->on($eventClass::eventName(), function ($event) use ($recorder, $eventClass): void {
                if ($event instanceof $eventClass) {
                    $recorder->events[] = $event;
                }
            });
        }

        return $recorder;
    }

    /**
     * Assert that an event was dispatched.
     *
     * @param class-string $eventClass Expected event class.
     * @return void
     */
    public function assertDispatched(string $eventClass): void
    {
        $dispatched = array_filter(
            $this->events,
            fn(object $event): bool => $event instanceof $eventClass,
        );

        expect($dispatched)->not->toBeEmpty(sprintf('Expected event [%s] to be dispatched.', $eventClass));
    }

    /**
     * Assert that an event was dispatched and matches a truth test.
     *
     * @param class-string $eventClass Expected event class.
     * @param callable $callback Truth test callback.
     * @return void
     */
    public function assertMatches(string $eventClass, callable $callback): void
    {
        $dispatched = array_filter(
            $this->events,
            fn(object $event): bool => $event instanceof $eventClass && $callback($event),
        );

        expect($dispatched)->not->toBeEmpty(sprintf('Expected event [%s] to be dispatched.', $eventClass));
    }

    /**
     * Assert that an event was not dispatched.
     *
     * @param class-string $eventClass Expected event class.
     * @return void
     */
    public function assertNotDispatched(string $eventClass): void
    {
        $dispatched = array_filter(
            $this->events,
            fn(object $event): bool => $event instanceof $eventClass,
        );

        expect($dispatched)->toBeEmpty(sprintf('Expected event [%s] not to be dispatched.', $eventClass));
    }

    /**
     * Get all recorded events.
     *
     * @return array<int, object>
     */
    public function events(): array
    {
        return $this->events;
    }
}
