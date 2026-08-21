<?php
declare(strict_types=1);

use Crustum\Ai\Http\Response;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Test\Fixtures\Responses\SubclassedStep;
use Crustum\Ai\Test\Fixtures\Responses\SubclassedTextResponse;

test('serialization preserves private properties declared on text response subclasses', function (): void {
    $response = new SubclassedTextResponse('Hello', new Usage(1, 2), new Meta('anthropic', 'claude'));
    $response->rememberSecret('changed');
    $response->withRawResponse(new Response(200, [], '{}'));

    $restored = unserialize(serialize($response));

    expect($restored->secret())->toBe('changed')
        ->and($restored->text)->toBe('Hello')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves private properties declared on step subclasses', function (): void {
    $step = new SubclassedStep('Hello', [], [], FinishReason::Stop, new Usage(1, 2), new Meta('anthropic', 'claude'));
    $step->rememberSecret('changed');
    $step->withRawResponse(new Response(200, [], '{}'));

    $restored = unserialize(serialize($step));

    expect($restored->secret())->toBe('changed')
        ->and($restored->text)->toBe('Hello')
        ->and($restored->raw)->toBeNull();
});
