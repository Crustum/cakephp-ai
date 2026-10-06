<?php
declare(strict_types=1);

use Cake\Chronos\Chronos;
use Crustum\Ai\Support\PendingSleep;
use Crustum\Ai\Support\Sleeper;

beforeEach(function (): void {
    Sleeper::fake();
});

afterEach(function (): void {
    Sleeper::reset();
    Chronos::setTestNow();
});

test('pending sleeps record durations in microseconds', function (): void {
    Sleeper::for(5)->seconds();
    Sleeper::for(250)->milliseconds();
    Sleeper::for(1500)->microseconds();
    Sleeper::for(2)->minutes();

    expect(Sleeper::sequence())->toBe([5_000_000, 250_000, 1_500, 120_000_000]);
});

test('singular unit aliases record the same durations', function (): void {
    Sleeper::for(1)->second();
    Sleeper::for(1)->millisecond();
    Sleeper::for(1)->microsecond();
    Sleeper::for(1)->minute();

    expect(Sleeper::sequence())->toBe([1_000_000, 1_000, 1, 60_000_000]);
});

test('fractional amounts round to whole microseconds', function (): void {
    Sleeper::for(0.5)->seconds();
    Sleeper::for(1.5)->milliseconds();

    expect(Sleeper::sequence())->toBe([500_000, 1_500]);
});

test('negative durations clamp to zero', function (): void {
    Sleeper::for(-5)->seconds();
    Sleeper::sleep(-1);
    Sleeper::usleep(-100);

    expect(Sleeper::sequence())->toBe([0, 0, 0]);
});

test('sleep and usleep shortcuts record in microseconds', function (): void {
    Sleeper::sleep(2);
    Sleeper::usleep(750);

    expect(Sleeper::sequence())->toBe([2_000_000, 750]);
});

test('and chaining accumulates into a single recorded duration', function (): void {
    Sleeper::for(2)->seconds()->and(500)->milliseconds();

    expect(Sleeper::sequence())->toBe([2_500_000]);
});

test('until sleeps the remaining microseconds to a future timestamp', function (): void {
    Chronos::setTestNow(Chronos::createFromTimestamp(1_000_000));

    Sleeper::until(1_000_005);

    expect(Sleeper::sequence())->toBe([5_000_000]);
});

test('until records zero for past timestamps', function (): void {
    Chronos::setTestNow(Chronos::createFromTimestamp(1_000_000));

    Sleeper::until(999_999);

    expect(Sleeper::sequence())->toBe([0]);
});

test('then sleeps once and returns the callback result', function (): void {
    $result = Sleeper::for(3)->seconds()->then(fn(): string => 'awake');

    expect($result)->toBe('awake')
        ->and(Sleeper::sequence())->toBe([3_000_000]);
});

test('when false skips the sleep', function (): void {
    Sleeper::for(3)->seconds()->when(false);
    Sleeper::for(3)->seconds()->unless(true);

    expect(Sleeper::sequence())->toBe([]);
});

test('when callbacks receive the pending sleep', function (): void {
    Sleeper::for(3)->seconds()->when(fn($sleep): bool => $sleep instanceof PendingSleep);

    expect(Sleeper::sequence())->toBe([3_000_000]);
});

test('fake callbacks receive the slept microseconds', function (): void {
    $seen = [];

    Sleeper::whenFakingSleep(function (int $microseconds) use (&$seen): void {
        $seen[] = $microseconds;
    });

    Sleeper::for(250)->milliseconds();

    expect($seen)->toBe([250_000]);
});

test('chronos test now advances when syncing is enabled', function (): void {
    Chronos::setTestNow(Chronos::createFromTimestamp(1_000_000));
    Sleeper::fake(syncWithChronos: true);

    Sleeper::for(90)->seconds();

    expect(Chronos::now()->getTimestamp())->toBe(1_000_090);
});

test('a unit call without an amount throws', function (): void {
    expect(fn(): mixed => Sleeper::for(5)->seconds()->seconds())->toThrow(RuntimeException::class, 'No duration specified.');
});
