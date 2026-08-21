<?php
declare(strict_types=1);

use Crustum\Ai\TestSuite\Http\RecordedHttp;

test('recorded HTTP exposes JSON and user text helpers', function (): void {
    $body = [
        'model' => 'gpt-5.4',
        'input' => [[
            'role' => 'user',
            'content' => [['type' => 'input_text', 'text' => 'Hello agent']],
        ]],
    ];
    $request = new RecordedHttp(
        'POST',
        'https://api.openai.com/v1/responses',
        $body,
        [],
        ['Authorization' => 'Bearer test'],
        json_encode($body) ?: '',
    );

    expect($request->json('model'))->toBe('gpt-5.4')
        ->and($request->hasUserText('Hello agent'))->toBeTrue()
        ->and($request->hasHeader('authorization', 'Bearer test'))->toBeTrue()
        ->and($request->summary())->toContain('model=gpt-5.4');
});

test('recorded HTTP detects tool output follow-up requests', function (): void {
    $body = [
        'previous_response_id' => 'resp_123',
        'input' => [[
            'type' => 'function_call_output',
            'call_id' => 'call_123',
            'output' => '42',
        ]],
    ];
    $request = new RecordedHttp(
        'POST',
        'https://api.openai.com/v1/responses',
        $body,
        [],
        [],
        json_encode($body) ?: '',
    );

    expect($request->containsToolOutput())->toBeTrue()
        ->and($request->summary())->toContain('previous_response_id', 'tool_outputs');
});
