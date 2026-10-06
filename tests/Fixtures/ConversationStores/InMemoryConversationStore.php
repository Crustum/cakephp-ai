<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\ConversationStores;

use Cake\Collection\Collection;
use Cake\Utility\Text;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Throwable;

class InMemoryConversationStore implements ConversationStore
{
    public array $conversations = [];

    public array $messages = [];

    public array $approvalResults = [];

    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        $matches = array_filter(
            $this->conversations,
            fn(array $conversation): bool => ($conversation['participant_type'] ?? null) === $participantType
                && ($conversation['participant_id'] ?? null) == $participantId,
        );

        return $matches === [] ? null : array_key_last($matches);
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        $id ??= Text::uuid();

        $this->conversations[$id] = [
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'title' => $title,
        ];

        return $id;
    }

    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        string $agent,
        UserMessage $message,
    ): string {
        $id = Text::uuid();

        $this->messages[] = [
            'id' => $id,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'role' => 'user',
            'content' => $message->content,
        ];

        return $id;
    }

    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response,
        ?Throwable $exception = null,
    ): ?string {
        $id = Text::uuid();

        $this->messages[] = [
            'id' => $id,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'role' => 'assistant',
            'content' => $response->text,
        ];

        return $id;
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return collection(
            collect($this->messages)->filter(fn($item): bool => is_array($item) && array_key_exists('conversation_id', $item) && $item['conversation_id'] === $conversationId)->toList(),
        )->take($limit);
    }

    public function storeApprovalResults(
        string $conversationId,
        array $toolResults,
    ): void {
        $this->approvalResults[] = [
            'conversation_id' => $conversationId,
            'tool_results' => $toolResults,
        ];
    }
}
