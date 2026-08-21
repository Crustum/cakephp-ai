<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Trait\PromptableTrait;

class HistoricalReasoningWithoutToolCallsAgent implements Agent, Conversational
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
            new UserMessage('What is 4+4?'),
            new AssistantMessage(
                'The answer is 8.',
                providerContentBlocks: ['reasoning_content' => 'Let me think... 4+4 = 8.'],
            ),
        ];
    }
}
