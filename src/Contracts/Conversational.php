<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * Interface for conversational AI interactions.
 *
 * Represents agents or objects that maintain a conversation history
 * through a series of messages.
 */
interface Conversational
{
    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return iterable<\Crustum\Ai\Messages\Message>
     */
    public function messages(): iterable;
}
