<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('cohere text responses expose the raw http response', function (): void {
    Configure::write('Ai.providers.cohere', [
        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);

    aiHttpFake(['*' => $this->fakeTextResponse('Hello there')]);

    $response = (new AssistantAgent())->prompt('Hi', provider: 'cohere');

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and(Hash::get($response->raw->getJson() ?? [], 'id'))->toBe('08067b1d-d35b-427c-a287-d913f35bd6ca');
});
