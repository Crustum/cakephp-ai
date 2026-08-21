<?php
declare(strict_types=1);

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Test\Fixtures\Tools\NonStrictTool;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;

test('isAppliedTo returns true when target has the attribute', function (): void {
    expect(Strict::isAppliedTo(new RandomNumberGenerator()))->toBeTrue();
});

test('isAppliedTo returns false when target does not have the attribute', function (): void {
    expect(Strict::isAppliedTo(new NonStrictTool()))->toBeFalse();
});

test('isAppliedTo returns false when target is null', function (): void {
    expect(Strict::isAppliedTo(null))->toBeFalse();
});
