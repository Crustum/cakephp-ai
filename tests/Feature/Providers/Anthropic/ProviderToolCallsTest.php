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

test('text before and after a server tool is separated by a blank line while citation-split text stays joined', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => 'Let me check.'],
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'cakephp ai']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                ['type' => 'text', 'text' => 'The latest stable version is '],
                ['type' => 'text', 'text' => 'CakePHP 5', 'citations' => [['type' => 'web_search_result_location', 'url' => 'https://example.com', 'title' => 'Example', 'cited_text' => 'CakePHP 5']]],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new AssistantAgent())->prompt('Search', provider: 'anthropic');

    expect($response->text)->toBe("Let me check.\n\nThe latest stable version is CakePHP 5");
});

test('text around a redacted thinking block is not separated', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => 'First'],
                ['type' => 'redacted_thinking', 'data' => 'encrypted'],
                ['type' => 'text', 'text' => 'Second'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new AssistantAgent())->prompt('Hello', provider: 'anthropic');

    expect($response->text)->toBe('FirstSecond');
});

test('text after a server tool is not separated when the model already ended the line', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => "Let me check.\n"],
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'cakephp ai']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                ['type' => 'text', 'text' => 'Done'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new AssistantAgent())->prompt('Search', provider: 'anthropic');

    expect($response->text)->toBe("Let me check.\nDone");
});
