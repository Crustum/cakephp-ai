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
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile>
     */
    public CollectionInterface $attachments;

    /**
     * Create a new user message instance.
     *
     * @param string $content The message content
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile>|array<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile> $attachments The message attachments
     */
    public function __construct(string $content, CollectionInterface|array $attachments = [])
    {
        parent::__construct('user', $content);

        $this->attachments = is_array($attachments) ? collection($attachments) : $attachments;
    }
}
