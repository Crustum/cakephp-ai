<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\StructuredStep;
use Crustum\Ai\Responses\Data\TextUsage;

test('structured step stores structured data', function (): void {
    $step = new StructuredStep(
        'test response',
        ['key' => 'value'],
        [],
        [],
        FinishReason::Stop,
        new TextUsage(),
        new Meta('openai', 'gpt-4o'),
        'Structuring the answer.',
        [],
    );

    expect($step->text)->toBe('test response')
        ->and($step->reasoning)->toBe('Structuring the answer.')
        ->and($step->structured)->toBe(['key' => 'value']);
});

test('structured step to array includes structured data', function (): void {
    $step = new StructuredStep(
        'text content',
        ['name' => 'test', 'count' => 5],
        [],
        [],
        FinishReason::Stop,
        new TextUsage(),
        new Meta('anthropic', 'claude-3'),
        '',
        [],
    );

    $array = $step->toArray();

    expect($array['text'])->toBe('text content')
        ->and($array['structured'])->toBe(['name' => 'test', 'count' => 5]);
});

test('structured step jsonSerialize includes structured data', function (): void {
    $step = new StructuredStep(
        'response text',
        ['result' => true],
        [],
        [],
        FinishReason::Stop,
        new TextUsage(),
        new Meta('openai', 'gpt-4o'),
        '',
        [],
    );

    $json = json_decode(json_encode($step), true);

    expect($json['structured'])->toBe(['result' => true])
        ->and($json['text'])->toBe('response text');
});
