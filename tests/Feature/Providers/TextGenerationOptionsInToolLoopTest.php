<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Gateway\Groq\GroqGateway;
use Crustum\Ai\Gateway\OpenAi\OpenAiGateway;
use Crustum\Ai\Providers\GroqProvider;
use Crustum\Ai\Providers\OpenAiProvider;
use Crustum\Ai\Test\Fixtures\Agents\TextGenOptionsToolAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

function textGenFakeOpenAiToolCallResponse(): AiHttpResponseDefinition
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

function textGenFakeOpenAiTextResponse(string $text): AiHttpResponseDefinition
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

function textGenFakeGroqToolCallResponse(): AiHttpResponseDefinition
{
    return fakeOpenRouterToolCallResponse();
}

function textGenFakeGroqTextResponse(string $text): AiHttpResponseDefinition
{
    return fakeOpenRouterResponse($text);
}

test('openai temperature and max tokens are preserved in tool call follow up', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            textGenFakeOpenAiToolCallResponse(),
            textGenFakeOpenAiTextResponse('The number is 72019'),
        ]),
    ]);

    Configure::write('Ai.providers.openai.key', 'test-key');

    $gateway = new OpenAiGateway(EventManager::instance());
    $provider = (new OpenAiProvider(Configure::read('Ai.providers.openai'), EventManager::instance()))
        ->useTextGateway($gateway);

    aiRegisterTextProvider('openai', $provider);

    (new TextGenOptionsToolAgent())->prompt('Give me a number', provider: 'openai');

    aiAssertHttpSentInOrder([
        fn(AiHttpRequest $request): bool => $request['temperature'] === 0.2
            && $request['max_output_tokens'] === 1024,
        fn(AiHttpRequest $request): bool => $request['temperature'] === 0.2
            && $request['max_output_tokens'] === 1024
            && isset($request['previous_response_id']),
    ]);
});

test('groq temperature and max tokens are preserved in tool call follow up', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            textGenFakeGroqToolCallResponse(),
            textGenFakeGroqTextResponse('The number is 72019'),
        ]),
    ]);

    Configure::write('Ai.providers.groq', [
        'className' => GroqProvider::class,
        'name' => 'groq',
        'driver' => 'groq',
        'key' => 'test-key',
    ]);

    $gateway = new GroqGateway(EventManager::instance());
    $provider = (new GroqProvider(Configure::read('Ai.providers.groq'), EventManager::instance()))
        ->useTextGateway($gateway);

    aiRegisterTextProvider('groq', $provider);

    (new TextGenOptionsToolAgent())->prompt('Give me a number', provider: 'groq');

    aiAssertHttpSentInOrder([
        fn(AiHttpRequest $request): bool => $request['temperature'] === 0.2
            && $request['max_completion_tokens'] === 1024,
        fn(AiHttpRequest $request): bool => $request['temperature'] === 0.2
            && $request['max_completion_tokens'] === 1024,
    ]);
});
