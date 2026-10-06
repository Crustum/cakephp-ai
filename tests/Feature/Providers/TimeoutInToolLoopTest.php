<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Providers\AnthropicProvider;
use Crustum\Ai\Providers\GeminiProvider;
use Crustum\Ai\Providers\GroqProvider;
use Crustum\Ai\Providers\OpenAiProvider;
use Crustum\Ai\Test\Fixtures\Agents\TimeoutToolAgent;
use Crustum\Ai\Test\Fixtures\Gateway\SpyAnthropicGateway;
use Crustum\Ai\Test\Fixtures\Gateway\SpyGeminiGateway;
use Crustum\Ai\Test\Fixtures\Gateway\SpyGroqGateway;
use Crustum\Ai\Test\Fixtures\Gateway\SpyOpenAiGateway;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

function timeoutFakeOpenAiToolCallResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'resp_tool_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_123',
            'call_id' => 'call_123',
            'name' => 'FixedNumberGenerator',
            'arguments' => '{}',
            'status' => 'completed',
        ]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function timeoutFakeOpenAiTextResponse(string $text): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ]);
}

function timeoutFakeAnthropicToolCallResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'msg_tool_123',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [[
            'type' => 'tool_use',
            'id' => 'toolu_123',
            'name' => 'FixedNumberGenerator',
            'input' => (object)[],
        ]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function timeoutFakeAnthropicTextResponse(string $text): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'msg_123',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function timeoutFakeGroqToolCallResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ]);
}

function timeoutFakeGroqTextResponse(string $text): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $text],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]);
}

function timeoutFakeGeminiToolCallResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse(timeoutFakeGeminiInteraction([[
        'type' => 'function_call',
        'id' => 'call_123',
        'name' => 'FixedNumberGenerator',
        'arguments' => (object)[],
    ]]));
}

function timeoutFakeGeminiTextResponse(string $text): AiHttpResponseDefinition
{
    return aiHttpResponse(timeoutFakeGeminiInteraction([[
        'type' => 'model_output',
        'content' => [['type' => 'text', 'text' => $text]],
    ]]));
}

function timeoutFakeGeminiInteraction(array $steps): array
{
    return [
        'id' => 'int_timeout',
        'model' => 'gemini-3.5-flash',
        'status' => 'completed',
        'steps' => $steps,
        'usage' => [
            'total_input_tokens' => 10,
            'total_output_tokens' => 5,
            'total_tokens' => 15,
        ],
    ];
}

test('openai timeout is preserved in tool call follow up', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            timeoutFakeOpenAiToolCallResponse(),
            timeoutFakeOpenAiTextResponse('The number is 72019'),
        ]),
    ]);

    Configure::write('Ai.providers.openai.key', 'test-key');

    $spy = new SpyOpenAiGateway(EventManager::instance());
    $provider = (new OpenAiProvider(Configure::read('Ai.providers.openai'), EventManager::instance()))
        ->useTextGateway($spy);

    aiRegisterTextProvider('openai', $provider);

    (new TimeoutToolAgent())->prompt('Give me a number', provider: 'openai');

    expect($spy->capturedTimeouts)->toHaveCount(2)
        ->and($spy->capturedTimeouts[0])->toBe(300)
        ->and($spy->capturedTimeouts[1])->toBe(300);
});

test('anthropic timeout is preserved in tool call follow up', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            timeoutFakeAnthropicToolCallResponse(),
            timeoutFakeAnthropicTextResponse('The number is 72019'),
        ]),
    ]);

    Configure::write('Ai.providers.anthropic.key', 'test-key');

    $spy = new SpyAnthropicGateway(EventManager::instance());
    $provider = (new AnthropicProvider(Configure::read('Ai.providers.anthropic'), EventManager::instance()))
        ->useTextGateway($spy);

    aiRegisterTextProvider('anthropic', $provider);

    (new TimeoutToolAgent())->prompt('Give me a number', provider: 'anthropic');

    expect($spy->capturedTimeouts)->toHaveCount(2)
        ->and($spy->capturedTimeouts[0])->toBe(300)
        ->and($spy->capturedTimeouts[1])->toBe(300);
});

test('groq timeout is preserved in tool call follow up', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            timeoutFakeGroqToolCallResponse(),
            timeoutFakeGroqTextResponse('The number is 72019'),
        ]),
    ]);

    Configure::write('Ai.providers.groq.key', 'test-key');

    $spy = new SpyGroqGateway(EventManager::instance());
    $provider = (new GroqProvider(Configure::read('Ai.providers.groq'), EventManager::instance()))
        ->useTextGateway($spy);

    aiRegisterTextProvider('groq', $provider);

    (new TimeoutToolAgent())->prompt('Give me a number', provider: 'groq');

    expect($spy->capturedTimeouts)->toHaveCount(2)
        ->and($spy->capturedTimeouts[0])->toBe(300)
        ->and($spy->capturedTimeouts[1])->toBe(300);
});

test('gemini timeout is preserved in tool call follow up', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            timeoutFakeGeminiToolCallResponse(),
            timeoutFakeGeminiTextResponse('The number is 72019'),
        ]),
    ]);

    Configure::write('Ai.providers.gemini.key', 'test-key');

    $spy = new SpyGeminiGateway(EventManager::instance());
    $provider = (new GeminiProvider(Configure::read('Ai.providers.gemini'), EventManager::instance()))
        ->useTextGateway($spy);

    aiRegisterTextProvider('gemini', $provider);

    (new TimeoutToolAgent())->prompt('Give me a number', provider: 'gemini');

    expect($spy->capturedTimeouts)->toHaveCount(2)
        ->and($spy->capturedTimeouts[0])->toBe(300)
        ->and($spy->capturedTimeouts[1])->toBe(300);
});
