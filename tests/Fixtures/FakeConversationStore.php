<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures;

use Cake\Collection\Collection;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;

class FakeConversationStore implements ConversationStore
{
    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        return null;
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        return 'conversation-123';
    }

    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
    ): string {
        return 'user-message-123';
    }

    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response,
    ): ?string {
        return 'assistant-message-123';
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return collection([]);
    }

    public function storeApprovalResults(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        array $toolResults,
    ): void {
    }
}
