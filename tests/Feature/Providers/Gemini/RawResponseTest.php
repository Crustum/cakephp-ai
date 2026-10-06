<?php
declare(strict_types=1);

use Cake\Utility\Hash;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('gemini text responses expose the raw http response', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('Hello there'),
    ]);

    $response = (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'steps.0.content.0.text'))->toBe('Hello there');
});
