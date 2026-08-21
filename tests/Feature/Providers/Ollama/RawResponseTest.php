<?php
declare(strict_types=1);

use Cake\Utility\Hash;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('ollama text responses expose the raw http response', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse('Hello there'),
    ]);

    $response = (new AssistantAgent())->prompt(
        'Hi',
        provider: 'ollama',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'model'))->toBe('llama3.1:8b');
});
