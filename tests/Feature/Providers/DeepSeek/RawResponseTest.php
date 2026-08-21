<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.deepseek', array_filter([
        ...(array)Configure::read('Ai.providers.deepseek'),
        'key' => 'test-key',
    ]));
});

test('deepseek text responses expose the raw http response', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => fakeDeepSeekResponse('Hello there'),
    ]);

    $response = (new AssistantAgent())->prompt(
        'Hi',
        provider: 'deepseek',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'id'))->toBe('chatcmpl-deepseek-123');
});
