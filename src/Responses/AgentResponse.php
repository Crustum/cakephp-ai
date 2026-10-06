<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;

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
     * Persisted user message row this turn wrote, if any.
     */
    public ?string $userMessageId = null;

    /**
     * Persisted assistant message row this turn wrote, if any.
     */
    public ?string $assistantMessageId = null;

    /**
     * Constructor
     *
     * @param string $invocationId The unique invocation identifier
     * @param string $text The generated text
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage Token usage information
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata about the response
     */
    public function __construct(string $invocationId, string $text, TextUsage $usage, Meta $meta)
    {
        $this->invocationId = $invocationId;

        parent::__construct($text, $usage, $meta);
    }

    /**
     * Create a fake response that reasoned before answering.
     *
     * @param string $reasoning Reasoning that preceded the answer
     * @param string $text Answer text
     */
    public static function fakeWithReasoning(string $reasoning, string $text = ''): self
    {
        $response = new self('fake-invocation', $text, new TextUsage(), new Meta());
        $response->reasoning = $reasoning;

        return $response;
    }

    /**
     * Create a fake response with tool calls pending approval.
     *
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Approvals\PendingApproval>|array<int, \Crustum\Ai\Approvals\PendingApproval> $pendingApprovals Pending approvals
     */
    public static function fakeWithPendingApprovals(CollectionInterface|array $pendingApprovals): self
    {
        $pendingApprovals = is_array($pendingApprovals) ? $pendingApprovals : $pendingApprovals->toList();

        return (new self('fake-invocation', '', new TextUsage(), new Meta()))
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
     * Set the conversation message rows this turn wrote.
     *
     * @param string|null $userMessageId Persisted user message row id
     * @param string|null $assistantMessageId Persisted assistant message row id
     */
    public function withStoredMessages(?string $userMessageId, ?string $assistantMessageId): static
    {
        $this->userMessageId = $userMessageId;
        $this->assistantMessageId = $assistantMessageId;

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
}
