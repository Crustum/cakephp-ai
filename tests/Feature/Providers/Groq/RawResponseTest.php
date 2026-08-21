<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.groq', [
        ...(array)Configure::read('Ai.providers.groq'),
        'key' => 'test-key',
    ]);
});

test('groq text responses expose the raw http response', function (): void {
    aiHttpFake([
        '*' => fakeGroqResponse('Hello there'),
    ]);

    $response = (new AssistantAgent())->prompt(
        'Hi',
        provider: 'groq',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'id'))->toBe('chatcmpl-123');
});
