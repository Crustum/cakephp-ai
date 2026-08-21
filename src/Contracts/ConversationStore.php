<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

use Cake\Collection\Collection;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;

/**
 * Interface for storing and retrieving conversations.
 *
 * Provides persistence for conversation history, allowing agents
 * to continue conversations across multiple interactions.
 */
interface ConversationStore
{
    /**
     * Get the most recent conversation ID for a given participant.
     *
     * @param string $participantType Participant morph / class type
     * @param string|int $participantId Participant identifier
     */
    public function latestConversationId(string $participantType, string|int $participantId): ?string;

    /**
     * Store a new conversation and return its ID.
     *
     * @param string|null $participantType Participant morph / class type
     * @param string|int|null $participantId Participant identifier
     * @param string $title Conversation title
     * @return string
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string;

    /**
     * Store a new user message for the given conversation and return its ID.
     *
     * @param string $conversationId The conversation identifier
     * @param string|null $participantType Participant morph / class type
     * @param string|int|null $participantId Participant identifier
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt The agent prompt
     * @return string
     */
    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
    ): string;

    /**
     * Store a new assistant message for the given conversation and return its ID.
     *
     * @param string $conversationId The conversation identifier
     * @param string|null $participantType Participant morph / class type
     * @param string|int|null $participantId Participant identifier
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt The agent prompt
     * @param \Crustum\Ai\Responses\AgentResponse $response The agent response
     * @return string|null
     */
    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response,
    ): ?string;

    /**
     * Get the latest messages for the given conversation.
     *
     * @param string $conversationId The conversation identifier
     * @param int $limit Maximum number of messages to retrieve
     * @return \Cake\Collection\Collection<int<0, max>, \Crustum\Ai\Messages\Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection;

    /**
     * Durably record resolved approval results on the paused turn before the run continues.
     *
     * @param string $conversationId The conversation identifier
     * @param string|null $participantType Participant morph / class type
     * @param string|int|null $participantId The participant identifier
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Resolved tool results
     * @return void
     * @throws \Crustum\Ai\Approvals\ApprovalMismatchException When no paused row matches the resolved results
     */
    public function storeApprovalResults(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        array $toolResults,
    ): void;
}
