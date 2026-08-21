<?php
declare(strict_types=1);

namespace Crustum\Ai\Messages;

use InvalidArgumentException;

/**
 * Base Message Class
 *
 * Represents a message in a conversation with an AI provider.
 */
class Message
{
    /**
     * The message role.
     */
    public MessageRole $role;

    /**
     * The message content.
     */
    public ?string $content;

    /**
     * Create a new text conversation message instance.
     *
     * @param \Crustum\Ai\Messages\MessageRole|string $role The message role
     * @param string|null $content The message content
     * @throws \InvalidArgumentException When an invalid role string is provided
     */
    public function __construct(MessageRole|string $role, ?string $content = '')
    {
        $this->content = $content;

        $this->role = $role instanceof MessageRole
            ? $role
            : (MessageRole::tryFrom($role) ?? throw new InvalidArgumentException('Invalid message role.'));
    }

    /**
     * Attempt to create a new message instance from the given value.
     *
     * @param mixed $message The message data (Message instance, array, or object)
     * @throws \InvalidArgumentException When unable to create message from given value
     */
    public static function tryFrom(mixed $message): self
    {
        return match (true) {
            $message instanceof self => $message,
            is_array($message) => new self($message['role'], $message['content']),
            is_object($message) => new self(
                property_exists($message, 'role') ? $message->role : throw new InvalidArgumentException('Object must have a role property.'),
                property_exists($message, 'content') ? $message->content : null,
            ),
            default => throw new InvalidArgumentException('Unable to create message from given value.'),
        };
    }
}
