<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

test('openrouter text responses expose the raw http response', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => fakeOpenRouterResponse('Hello there'),
    ]);

    $response = (new AssistantAgent())->prompt(
        'Hi',
        provider: 'openrouter',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'id'))->toBe('chatcmpl-123');
});
