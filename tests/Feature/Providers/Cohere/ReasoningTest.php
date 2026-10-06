<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.cohere', [
        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);
});

test('prompt reads the thinking blocks off the response', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse([
        ['type' => 'thinking', 'thinking' => 'Let me think...'],
        ['type' => 'text', 'text' => 'Hello'],
    ])]);

    $response = (new AssistantAgent())->prompt('Hi', provider: 'cohere');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
});

test('prompt separates each thinking block with a blank line', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse([
        ['type' => 'thinking', 'thinking' => 'First.'],
        ['type' => 'thinking', 'thinking' => '   '],
        ['type' => 'thinking', 'thinking' => 'Second.'],
        ['type' => 'text', 'text' => 'Hello'],
    ])]);

    expect((new AssistantAgent())->prompt('Hi', provider: 'cohere')->reasoning)->toBe("First.\n\nSecond.");
});
