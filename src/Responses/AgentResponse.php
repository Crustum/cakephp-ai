<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;

/**
 * Agent Response
 *
 * Represents a response from an agent invocation.
 */
class AgentResponse extends TextResponse
{
    /**
     * Invocation identifier
     */
    public string $invocationId;

    /**
     * Conversation identifier
     */
    public ?string $conversationId = null;

    /**
     * Conversation user/participant
     */
    public ?object $conversationUser = null;

    /**
     * Constructor
     *
     * @param string $invocationId The unique invocation identifier
     * @param string $text The generated text
     * @param \Crustum\Ai\Responses\Data\Usage $usage Token usage information
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata about the response
     */
    public function __construct(string $invocationId, string $text, Usage $usage, Meta $meta)
    {
        $this->invocationId = $invocationId;

        parent::__construct($text, $usage, $meta);
    }

    /**
     * Create a fake response with tool calls pending approval.
     *
     * @param \Cake\Collection\CollectionInterface|array<int, \Crustum\Ai\Approvals\PendingApproval> $pendingApprovals Pending approvals
     */
    public static function fakeWithPendingApprovals(CollectionInterface|array $pendingApprovals): self
    {
        $pendingApprovals = is_array($pendingApprovals) ? $pendingApprovals : $pendingApprovals->toList();

        return (new self('fake-invocation', '', new Usage(), new Meta()))
            ->withPendingApprovals(collection($pendingApprovals));
    }

    /**
     * Set the conversation UUID and participant for this response.
     *
     * @param string $conversationId The conversation identifier
     * @param object|null $conversationUser The conversation user/participant
     */
    public function withinConversation(string $conversationId, ?object $conversationUser = null): static
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $conversationUser;

        return $this;
    }

    /**
     * Execute a callback with this response.
     *
     * @param callable $callback The callback to execute
     */
    public function then(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * Get the raw provider replay state for the paused assistant turn, if any.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pausedProviderContentBlocks(): array
    {
        if (!$this->hasPendingApprovals()) {
            return [];
        }

        /** @var \Crustum\Ai\Messages\AssistantMessage|null $last */
        $last = $this->messages
            ->filter(fn($message): bool => $message instanceof AssistantMessage)
            ->last();

        if (!$last instanceof AssistantMessage) {
            return [];
        }

        return $last->providerContentBlocks;
    }
}
