<?php
declare(strict_types=1);

use Cake\Collection\Collection;
use Cake\ORM\Entity;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;

beforeEach(function (): void {
    Ai::manager()->resetFakeState();
});

test('it threads the participant class into latestConversationId when continuing the last conversation', function (): void {
    $participant = new class extends Entity {
    };

    $participant->set('id', 7);

    $store = new class ($participant::class) implements ConversationStore {
        public function __construct(private string $expectedType)
        {
        }

        public ?string $receivedType = null;

        public ?string $receivedAgent = null;

        public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
        {
            $this->receivedType = $participantType;
            $this->receivedAgent = $agent;

            return $participantType === $this->expectedType ? 'conversation-typed' : null;
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(
            string $conversationId,
            ?string $participantType,
            string|int|null $participantId,
            string $agent,
            UserMessage $message,
        ): string {
            return 'user-1';
        }

        public function storeAssistantMessage(
            string $conversationId,
            ?string $participantType,
            string|int|null $participantId,
            AgentPrompt $prompt,
            AgentResponse $response,
            ?Throwable $exception = null,
        ): string {
            return 'assistant-1';
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
    };

    Ai::manager()->setConversationStore($store);

    $agent = (new RememberingAssistantAgent())->continueLastConversation($participant);

    expect($store->receivedType)->toBe($participant::class)
        ->and($store->receivedAgent)->toBe(RememberingAssistantAgent::class)
        ->and($agent->currentConversation())->toBe('conversation-typed');
});

test('it continues the last conversation through a store that ignores the participant type', function (): void {
    $participant = new class {
        public int $id = 7;
    };

    $store = new class implements ConversationStore {
        public function latestConversationId(string $participantType, string|int $participantId, string $agent): string
        {
            return 'conversation-1';
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(
            string $conversationId,
            ?string $participantType,
            string|int|null $participantId,
            string $agent,
            UserMessage $message,
        ): string {
            return 'user-1';
        }

        public function storeAssistantMessage(
            string $conversationId,
            ?string $participantType,
            string|int|null $participantId,
            AgentPrompt $prompt,
            AgentResponse $response,
            ?Throwable $exception = null,
        ): string {
            return 'assistant-1';
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
    };

    Ai::manager()->setConversationStore($store);

    $agent = (new RememberingAssistantAgent())->continueLastConversation($participant);

    expect($agent->currentConversation())->toBe('conversation-1');
});

test('it resolves the participant id from an entity id field', function (): void {
    $participant = new class extends Entity {
    };

    $participant->set('id', 'uuid-123');

    $store = new class implements ConversationStore {
        public string|int|null $receivedId = null;

        public function latestConversationId(string $participantType, string|int $participantId, string $agent): string
        {
            $this->receivedId = $participantId;

            return 'conversation-1';
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(
            string $conversationId,
            ?string $participantType,
            string|int|null $participantId,
            string $agent,
            UserMessage $message,
        ): string {
            return 'user-1';
        }

        public function storeAssistantMessage(
            string $conversationId,
            ?string $participantType,
            string|int|null $participantId,
            AgentPrompt $prompt,
            AgentResponse $response,
            ?Throwable $exception = null,
        ): string {
            return 'assistant-1';
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
    };

    Ai::manager()->setConversationStore($store);

    (new RememberingAssistantAgent())->continueLastConversation($participant);

    expect($store->receivedId)->toBe('uuid-123');
});

test('it starts a conversation for the participant when no conversation id is given', function (): void {
    $participant = new class {
        public int $id = 7;
    };

    $agent = (new RememberingAssistantAgent())->continueOrStart(null, as: $participant);

    expect($agent->currentConversation())->toBeNull()
        ->and($agent->conversationParticipant())->toBe($participant);
});

test('it continues the given conversation for the participant', function (): void {
    $participant = new class {
        public int $id = 7;
    };

    $agent = (new RememberingAssistantAgent())->continueOrStart('conversation-1', as: $participant);

    expect($agent->currentConversation())->toBe('conversation-1')
        ->and($agent->conversationParticipant())->toBe($participant);
});
