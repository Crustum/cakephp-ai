<?php
declare(strict_types=1);

use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

if (!function_exists('fakeOpenAiResponse')) {
    /**
     * Build a fake OpenAI text response.
     *
     * @param string $text Response text
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeOpenAiResponse(string $text = ''): AiHttpResponseDefinition
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
}

if (!function_exists('fakeOpenAiToolCallResponse')) {
    /**
     * Build a fake OpenAI tool call response.
     *
     * @param string $id Response ID
     * @param string $model Model name
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeOpenAiToolCallResponse(string $id = 'resp_tool_123', string $model = 'gpt-5.4'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => $id,
            'status' => 'completed',
            'model' => $model,
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
}

if (!function_exists('fakeOpenRouterResponse')) {
    /**
     * Build a fake OpenRouter text response.
     *
     * @param string $content Response content
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeOpenRouterResponse(string $content = 'Hello'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);
    }
}

if (!function_exists('fakeOllamaResponse')) {
    /**
     * Build a fake Ollama text response.
     *
     * @param string $text Response text
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeOllamaResponse(string $text = 'Hello'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'model' => 'llama3.1:8b',
            'message' => [
                'role' => 'assistant',
                'content' => $text,
            ],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 1,
            'eval_count' => 1,
        ]);
    }
}

if (!function_exists('fakeOpenRouterToolCallResponse')) {
    /**
     * Build a fake OpenRouter tool call response.
     *
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeOpenRouterToolCallResponse(): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'chatcmpl-tool-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
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
}

if (!function_exists('fakeDeepSeekResponse')) {
    /**
     * Build a fake DeepSeek text response.
     *
     * @param string $text Response text
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeDeepSeekResponse(string $text = 'Hello'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'chatcmpl-deepseek-123',
            'object' => 'chat.completion',
            'model' => 'deepseek-chat',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]);
    }
}

if (!function_exists('fakeDeepSeekToolCallResponse')) {
    /**
     * Build a fake DeepSeek tool call response.
     *
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeDeepSeekToolCallResponse(): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'chatcmpl-deepseek-tool-123',
            'object' => 'chat.completion',
            'model' => 'deepseek-chat',
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
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }
}

if (!function_exists('fakeAzureResponse')) {
    /**
     * Build a fake Azure OpenAI text response.
     *
     * @param string $text Response text
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeAzureResponse(string $text = 'Hello'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'resp_azure_123',
            'status' => 'completed',
            'model' => 'gpt-4o',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => $text,
                ]],
            ]],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
            ],
        ]);
    }
}

if (!function_exists('fakeGroqResponse')) {
    /**
     * Build a fake Groq text response.
     *
     * @param string $text Response text
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeGroqResponse(string $text = 'Hello'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $text,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]);
    }
}

if (!function_exists('fakeGroqToolCallResponse')) {
    /**
     * Build a fake Groq tool call response.
     *
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function fakeGroqToolCallResponse(): AiHttpResponseDefinition
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
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }
}
