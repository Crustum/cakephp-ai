<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures;

use Cake\Collection\Collection;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Throwable;

class FakeConversationStore implements ConversationStore
{
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return null;
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        return $id ?? 'conversation-123';
    }

    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        string $agent,
        UserMessage $message,
    ): string {
        return 'user-message-123';
    }

    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response,
        ?Throwable $exception = null,
    ): ?string {
        return 'assistant-message-123';
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return collection([]);
    }

    public function storeApprovalResults(
        string $conversationId,
        array $toolResults,
    ): void {
    }
}
