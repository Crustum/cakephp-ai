<?php
declare(strict_types=1);

namespace Crustum\Ai\Messages;

use Cake\Collection\CollectionInterface;

/**
 * Assistant Message Class
 *
 * Represents a message from the AI assistant with optional tool calls.
 */
class AssistantMessage extends Message
{
    /**
     * The message's tool calls.
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall>
     */
    public CollectionInterface $toolCalls;

    /**
     * Raw provider replay state populated by the SDK's response parser.
     *
     * @var array<int|string, mixed>
     */
    public array $replayBlocks = [];

    /**
     * The provider the replay state belongs to, or null when it was produced within the current run.
     */
    public ?string $replayBlocksProvider = null;

    /**
     * Create a new assistant message instance.
     *
     * @param string $content The message content
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall>|null $toolCalls The tool calls made by the assistant
     * @param array<array-key, mixed> $replayBlocks Raw provider content blocks
     * @param string|null $replayBlocksProvider Provider that owns the replay state
     */
    public function __construct(
        string $content,
        ?CollectionInterface $toolCalls = null,
        array $replayBlocks = [],
        ?string $replayBlocksProvider = null,
    ) {
        parent::__construct('assistant', $content);

        $this->toolCalls = $toolCalls ?: collection([]);
        $this->replayBlocks = $replayBlocks;
        $this->replayBlocksProvider = $replayBlocksProvider;
    }
}
