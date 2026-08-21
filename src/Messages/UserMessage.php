<?php
declare(strict_types=1);

namespace Crustum\Ai\Messages;

use Cake\Collection\CollectionInterface;

/**
 * User Message Class
 *
 * Represents a message from the user with optional attachments.
 */
class UserMessage extends Message
{
    /**
     * The message's attachments.
     */
    public CollectionInterface $attachments;

    /**
     * Create a new user message instance.
     *
     * @param string $content The message content
     * @param \Cake\Collection\CollectionInterface|array $attachments The message attachments
     */
    public function __construct(string $content, CollectionInterface|array $attachments = [])
    {
        parent::__construct('user', $content);

        $this->attachments = is_array($attachments) ? collection($attachments) : $attachments;
    }
}
