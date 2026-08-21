<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Test\Support\IntegrationPrompts;
use Crustum\Ai\Trait\PromptableTrait;

class ConversationalAgent implements Agent, Conversational
{
    use PromptableTrait;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that responds extremely concisely to all queries.';
    }

    /**
     * Get the list of messages comprising the conversation so far.
     */
    public function messages(): iterable
    {
        $context = IntegrationPrompts::context('conversation_name');

        return $context === null
            ? []
            : [new Message(role: 'user', content: $context)];
    }
}
