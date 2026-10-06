<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data\ProviderToolCall;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('code execution steps land on the step', function (): void {
    $call = [
        'type' => 'code_execution_call',
        'id' => 'ce_1',
        'content' => [['type' => 'text', 'text' => 'print(1)']],
    ];

    $result = [
        'type' => 'code_execution_result',
        'id' => 'ce_1',
        'content' => [['type' => 'text', 'text' => '1']],
    ];

    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse($this->fakeInteraction([
            $call,
            $result,
            $this->modelOutput('The answer is 1.'),
        ])),
    ]);

    $response = (new AssistantAgent())->prompt('Run it', provider: 'gemini');

    expect(array_map(fn(ProviderToolCall $call): array => $call->data, $response->steps->first()->providerToolCalls))
        ->toBe([$call, $result])
        ->and($response->steps->first()->providerToolCalls[0]->type)->toBe('code_execution')
        ->and($response->steps->first()->providerToolCalls[1]->type)->toBe('code_execution')
        ->and($response->text)->toBe('The answer is 1.');
});

test('google search steps are reported as provider tool calls', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse($this->fakeInteraction([
            ['type' => 'google_search_call', 'content' => [['type' => 'text', 'text' => 'euro 2024 winner']]],
            ['type' => 'google_search_result', 'content' => [['type' => 'text', 'text' => 'Spain won.']]],
            $this->modelOutput('Spain won Euro 2024.'),
        ])),
    ]);

    $response = (new AssistantAgent())->prompt('Who won?', provider: 'gemini');

    expect(array_map(fn(ProviderToolCall $call): string => $call->type, $response->steps->first()->providerToolCalls))
        ->toBe(['google_search', 'google_search']);
});
