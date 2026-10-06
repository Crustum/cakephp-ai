<?php
declare(strict_types=1);

namespace Crustum\Ai\Vercel;

use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Contracts\AgentInput;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Streaming\Protocols\VercelDataProtocol;

/**
 * Vercel Chat
 *
 * Converts Vercel AI SDK UI message arrays into agent-compatible input.
 * Implements AgentInput to allow prompting agents with useChat request bodies.
 */
class Chat implements AgentInput
{
    /**
     * Constructor.
     *
     * @param array<int, array<string, mixed>> $messages UI messages from useChat
     */
    public function __construct(protected array $messages)
    {
    }

    /**
     * Get the newest user message, if the input contains one.
     *
     * @return \Crustum\Ai\Messages\UserMessage|null
     */
    public function message(): ?UserMessage
    {
        $message = static::latestOfRole($this->messages, 'user');

        if ($message === null) {
            return null;
        }

        $converted = Vercel::fromUiMessage($message);

        return $converted instanceof UserMessage ? $converted : null;
    }

    /**
     * Get the tool approval decisions from the newest assistant message, if the input contains any.
     *
     * @return \Crustum\Ai\Approvals\Decisions|null
     */
    public function decisions(): ?Decisions
    {
        $message = static::latestOfRole($this->precedingMessages(), 'assistant');

        $responses = $message === null ? [] : Vercel::approvalResponsesFrom($message);

        return $responses === [] ? null : Decisions::from($responses);
    }

    /**
     * Get every turn before the one this input resolves to.
     *
     * @return list<\Crustum\Ai\Messages\Message>
     */
    public function history(): array
    {
        return Vercel::fromUiMessages($this->precedingMessages());
    }

    /**
     * Get the ID of the assistant message the response continues, if any.
     *
     * @return string|null
     */
    public function messageId(): ?string
    {
        return static::latestOfRole($this->messages, 'assistant')['id'] ?? null;
    }

    /**
     * Get the stream protocol for the chat.
     *
     * @return \Crustum\Ai\Streaming\Protocols\VercelDataProtocol
     */
    public function protocol(): VercelDataProtocol
    {
        return new VercelDataProtocol($this->messageId());
    }

    /**
     * Get the raw messages preceding the turn this input resolves to.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function precedingMessages(): array
    {
        return static::latestOfRole($this->messages, 'user') === null
            ? $this->messages
            : array_slice($this->messages, 0, -1);
    }

    /**
     * Get the newest of the given messages when it matches the given role.
     *
     * @param array<int, array<string, mixed>> $messages Messages to search
     * @param string $role The role to match
     * @return array<string, mixed>|null
     */
    protected static function latestOfRole(array $messages, string $role): ?array
    {
        $message = $messages === [] ? null : $messages[array_key_last($messages)];

        return ($message['role'] ?? null) === $role ? $message : null;
    }
}
