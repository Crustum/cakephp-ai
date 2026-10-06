<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Responses\Data\ProviderToolCall;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Test\Fixtures\Agents\OpenAiAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('provider tool output items land on the step', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse([
            'id' => 'resp_1',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'cakephp ai']],
                ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'ignored', 'arguments' => '{}'],
                ['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Found it.']]],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]),
    ]);

    $response = (new OpenAiAgent())->prompt('Search');

    expect($response->steps->first()->providerToolCalls)->toHaveCount(1)
        ->and($response->steps->first()->providerToolCalls[0])->toBeInstanceOf(ProviderToolCall::class)
        ->and($response->steps->first()->providerToolCalls[0]->toArray())->toBe([
            'id' => 'ws_1',
            'type' => 'web_search_call',
            'data' => ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'cakephp ai']],
        ]);
});

test('streamed provider tool items land on the step', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed']],
                $this->outputTextDelta('Found it.'),
                $this->outputTextDone('Found it.'),
                ['type' => 'response.completed', 'response' => [
                    'id' => 'resp_1',
                    'model' => 'gpt-5.4',
                    'status' => 'completed',
                    'output' => [['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed']],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $streamEnd = collection(iterator_to_array((new OpenAiAgent())->stream('Search')))->filter(fn($event): bool => $event instanceof StreamEnd)->first();

    expect(array_map(fn(ProviderToolCall $call): string => $call->id, $streamEnd->steps->first()->providerToolCalls))->toBe(['ws_1']);
});
