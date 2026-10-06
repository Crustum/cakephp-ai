<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data\ProviderToolCall;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('server tool use and result blocks land on the step keyed by the tool use', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'cakephp ai']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                ['type' => 'text', 'text' => 'Found it.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new AssistantAgent())->prompt('Search', provider: 'anthropic');

    expect(array_map(fn(ProviderToolCall $call): array => [$call->id, $call->type], $response->steps->first()->providerToolCalls))
        ->toBe([['srvtoolu_1', 'server_tool_use'], ['srvtoolu_1', 'web_search_tool_result']])
        ->and($response->steps->first()->providerToolCalls[0]->data['input'])->toBe(['query' => 'cakephp ai']);
});
