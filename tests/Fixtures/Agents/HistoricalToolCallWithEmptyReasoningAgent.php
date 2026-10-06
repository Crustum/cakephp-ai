<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Trait\PromptableTrait;

class HistoricalToolCallWithEmptyReasoningAgent implements Agent, Conversational
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function provider(): string
    {
        return 'deepseek';
    }

    public function messages(): iterable
    {
        return [
            new UserMessage('check stock'),
            new AssistantMessage(
                'Found the products.',
                collect([
                    new ToolCall('call_empty', 'SearchProducts', ['keyword' => 'lampu'], 'call_empty'),
                ]),
                [['type' => 'reasoning', 'reasoning_content' => '']],
            ),
            new ToolResultMessage(collect([
                new ToolResult('call_empty', 'SearchProducts', ['keyword' => 'lampu'], '[{"name":"Lampu LED"}]', 'call_empty'),
            ])),
            new UserMessage('tell me more'),
        ];
    }
}
