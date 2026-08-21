<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.xai', [
        ...(array)Configure::read('Ai.providers.xai'),
        'key' => 'test-key',
    ]);
});

test('xai text responses expose the raw http response', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse('Hello there'),
    ]);

    $response = (new AssistantAgent())->prompt(
        'Hi',
        provider: 'xai',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'id'))->toBe('resp_123');
});
