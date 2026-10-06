<?php
declare(strict_types=1);

namespace Crustum\Ai\Storage;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\Database\Driver\Sqlite;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\Routing\Router;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Contracts\PaginatesConversations;
use Crustum\Ai\Contracts\ResolvesPendingApprovals;
use Crustum\Ai\Contracts\VerifiesConversationOwnership;
use Crustum\Ai\Enums\MessageStatus;
use Crustum\Ai\Files\File;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Model\Entity\ConversationMessage;
use Crustum\Ai\Model\Table\ConversationMessagesTable;
use Crustum\Ai\Model\Table\ConversationsTable;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\ProviderToolCall;
use Crustum\Ai\Responses\Data\Step;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Utility\Uuid;
use Crustum\Ai\Utility\Value;
use InvalidArgumentException;
use Throwable;

/**
 * Database-backed conversation store.
 */
class DatabaseConversationStore implements ConversationStore, PaginatesConversations, ResolvesPendingApprovals, VerifiesConversationOwnership
{
    protected TableLocator $tableLocator;

    /**
     * @param string|null $connection Connection name.
     */
    public function __construct(protected ?string $connection = null)
    {
        $this->tableLocator = new TableLocator();
    }

    /**
     * @inheritDoc
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        /** @var \Crustum\Ai\Model\Entity\ConversationMessage|null $record */
        $record = $this->messagesTable()
            ->find()
            ->select(['conversation_id'])
            ->where([
                'participant_type' => $participantType,
                'participant_id' => (string)$participantId,
                'agent' => $agent,
            ])
            ->orderByDesc('id')
            ->first();

        return $record?->conversation_id;
    }

    /**
     * Determine whether the given conversation was stored for the given participant.
     *
     * @param string $conversationId Conversation identifier
     * @param string|null $participantType Participant type
     * @param string|int|null $participantId Participant identifier
     */
    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool
    {
        /** @var \Crustum\Ai\Model\Entity\Conversation|null $conversation */
        $conversation = $this->conversationsTable()
            ->find()
            ->select(['participant_type', 'participant_id'])
            ->where(['id' => $conversationId])
            ->first();

        return $conversation !== null
            && $conversation->participant_type === $participantType
            && (string)$conversation->participant_id === (string)$participantId;
    }

    /**
     * @inheritDoc
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        $conversationId = $id ?? Uuid::v7();
        $now = DateTime::now();

        $this->conversationsTable()->saveOrFail($this->conversationsTable()->newEntity([
            'id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId === null ? null : (string)$participantId,
            'title' => $title,
            'created' => $now,
            'modified' => $now,
        ]));

        return $conversationId;
    }

    /**
     * @inheritDoc
     */
    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        string $agent,
        UserMessage $message,
    ): string {
        $messageId = Uuid::v7();
        $now = DateTime::now();

        $this->messagesTable()->saveOrFail($this->messagesTable()->newEntity([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId === null ? null : (string)$participantId,
            'agent' => $agent,
            'role' => 'user',
            'content' => $message->content,
            'attachments' => $this->serializeAttachments($message->attachments),
            'steps' => [],
            'usage_data' => [],
            'meta' => [],
            'status' => MessageStatus::Completed->value,
            'created' => $now,
            'modified' => $now,
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * @inheritDoc
     */
    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response,
        ?Throwable $exception = null,
    ): ?string {
        $paused = $prompt->hasApprovalDecisions() ? $this->pausedRowFor($conversationId, $prompt) : null;

        if ($paused instanceof ConversationMessage) {
            return $this->resumePausedRow($conversationId, $paused, $prompt, $response, $exception);
        }

        $messageId = Uuid::v7();
        $now = DateTime::now();

        $steps = $this->stepsFor($prompt, $response);

        if ($prompt->hasApprovalDecisions() && Value::blank($response->text) && $steps->every(fn(array $step): bool => $step['tool_calls'] === [])) {
            return null;
        }

        $this->messagesTable()->saveOrFail($this->messagesTable()->newEntity([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId === null ? null : (string)$participantId,
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $response->text,
            'attachments' => [],
            'steps' => $steps->toList(),
            'usage_data' => $response->usage->toArray(),
            'meta' => $this->metaFor($response, $exception),
            'status' => $this->statusFor($response, $exception)->value,
            'created' => $now,
            'modified' => $now,
        ]));

        if (!$response->hasPendingApprovals()) {
            $this->forgetReplayBlocks($conversationId);
        }

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * The status the turn is stored under, given how it ended.
     *
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response
     * @param \Throwable|null $exception The error the run died with, if it did
     */
    protected function statusFor(AgentResponse $response, ?Throwable $exception): MessageStatus
    {
        return match (true) {
            $exception instanceof Throwable => MessageStatus::Failed,
            $response->hasPendingApprovals() => MessageStatus::Paused,
            default => MessageStatus::Completed,
        };
    }

    /**
     * The meta the turn is stored under, carrying the error it died with.
     *
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response
     * @param \Throwable|null $exception The error the run died with, if it did
     * @return array<string, mixed>
     */
    protected function metaFor(AgentResponse $response, ?Throwable $exception): array
    {
        return $exception instanceof Throwable
            ? [...$response->meta->toArray(), 'error' => $exception->getMessage()]
            : $response->meta->toArray();
    }

    /**
     * Find the row the given resume paused on, matching the turn its decisions name.
     *
     * @param string $conversationId Conversation identifier
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     */
    protected function pausedRowFor(string $conversationId, AgentPrompt $prompt): ?ConversationMessage
    {
        $decided = array_keys($prompt->approvalDecisions->all());

        /** @var list<\Crustum\Ai\Model\Entity\ConversationMessage> $rows */
        $rows = $this->assistantRows($conversationId)
            ->where(['status' => MessageStatus::Paused->value])
            ->all()
            ->toList();

        foreach ($rows as $row) {
            if (array_intersect($this->gatedCallIds($row), $decided) !== []) {
                return $row;
            }
        }

        $newest = $this->assistantRows($conversationId)->all()->toList()[0] ?? null;

        if (!$newest instanceof ConversationMessage) {
            return null;
        }

        return MessageStatus::from($newest->status instanceof MessageStatus ? $newest->status->value : (string)$newest->status) !== MessageStatus::Paused ? null : $newest;
    }

    /**
     * Append the steps a resumed run made to the row its turn paused on.
     *
     * @param string $conversationId Conversation identifier
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $paused Paused message record
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response
     */
    protected function resumePausedRow(
        string $conversationId,
        ConversationMessage $paused,
        AgentPrompt $prompt,
        AgentResponse $response,
        ?Throwable $exception = null,
    ): string {
        $steps = $this->decodedSteps($paused);

        if (($this->decoded($paused->meta)['provider'] ?? null) !== $response->meta->provider) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        if (!$response->steps->isEmpty()) {
            $steps = $steps->append($this->stepsFor($prompt, $response));
        }

        if (!$response->hasPendingApprovals()) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        $now = DateTime::now();

        $paused->content = Value::blank($response->text) ? $paused->content : $response->text;
        $paused->steps = $steps->toList();
        $paused->usage_data = TextUsage::fromArray($this->decoded($paused->usage_data))->add($response->usage)->toArray();
        $paused->meta = $this->mergedMeta($paused, $response, $exception);
        $paused->status = $this->statusFor($response, $exception);
        $paused->modified = $now;

        $this->messagesTable()->saveOrFail($paused);

        if (!$response->hasPendingApprovals()) {
            $this->forgetReplayBlocks($conversationId);
        }

        $this->touchConversation($conversationId, $now);

        return $paused->id;
    }

    /**
     * Keep the citations the paused half of the turn collected, under the resuming provider and model.
     *
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $paused Paused message record
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response
     * @param \Throwable|null $exception The error the run died with, if it did
     * @return array<string, mixed>
     */
    protected function mergedMeta(ConversationMessage $paused, AgentResponse $response, ?Throwable $exception = null): array
    {
        return [
            ...$this->metaFor($response, $exception),
            'citations' => [...$this->decoded($paused->meta)['citations'] ?? [], ...$response->meta->citations],
        ];
    }

    /**
     * The assistant rows of the given conversation, newest first.
     *
     * @param string $conversationId Conversation identifier
     * @return \Cake\ORM\Query\SelectQuery<\Crustum\Ai\Model\Entity\ConversationMessage>
     */
    protected function assistantRows(string $conversationId): SelectQuery
    {
        return $this->messagesTable()
            ->find()
            ->where(['conversation_id' => $conversationId, 'role' => 'assistant'])
            ->orderByDesc('id');
    }

    /**
     * Serialize the turn's steps, one entry per model round-trip.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt.
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response.
     * @return \Cake\Collection\CollectionInterface<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function stepsFor(AgentPrompt $prompt, AgentResponse $response): CollectionInterface
    {
        $reasons = $this->pendingReasonsFor($response);

        if (!$response->steps->isEmpty()) {
            /** @var \Cake\Collection\CollectionInterface<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}> $mapped */
            $mapped = $response->steps->map(fn(Step $step): array => [
                'content' => $step->text,
                'tool_calls' => $this->toolCallsFor($step->toolCalls, $step->toolResults, $reasons),
                'reasoning' => $step->reasoning,
                'replay_blocks' => $response->hasPendingApprovals() ? $step->replayBlocks : [],
                'provider_tool_calls' => array_map(fn(ProviderToolCall $call): array => $call->toArray(), $step->providerToolCalls),
            ]);

            return $mapped;
        }

        // A resume that ran no step only carries the approval results storeApprovalResults() already wrote to the paused row.
        return collection([[
            'content' => $response->text,
            'tool_calls' => $this->toolCallsFor(
                $response->toolCalls->toList(),
                $prompt->hasApprovalDecisions() ? [] : $response->toolResults->toList(),
                $reasons,
            ),
            'reasoning' => $response->reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]]);
    }

    /**
     * Pair a step's tool calls with the results they were answered by, marking those awaiting approval with their reason.
     *
     * @param iterable<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls Tool calls.
     * @param iterable<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Tool results.
     * @param \Cake\Collection\CollectionInterface<string, string|null> $reasons Approval reasons keyed by tool call ID.
     * @return list<array<string, mixed>>
     */
    protected function toolCallsFor(iterable $toolCalls, iterable $toolResults, CollectionInterface $reasons): array
    {
        $results = [];

        foreach ($toolResults as $result) {
            $results[$result->id] = $result;
        }

        $reasonsById = $reasons->toArray();
        $paired = [];

        foreach ($toolCalls as $toolCall) {
            $result = $results[$toolCall->id] ?? null;

            $stored = array_diff_key(
                $toolCall->toArray(),
                ['reasoning_id' => true, 'reasoning_summary' => true, 'reasoning_encrypted_content' => true],
            );

            if ($toolCall->thoughtSignature === null) {
                unset($stored['thought_signature']);
            }

            $entry = $stored;

            if (array_key_exists($toolCall->id, $reasonsById)) {
                $entry['approval_reason'] = $reasonsById[$toolCall->id];
            }

            if ($result !== null) {
                $entry += array_intersect_key(
                    $result->toArray(),
                    ['result' => true, 'denied' => true, 'failed' => true],
                );
            }

            $paired[] = $entry;
        }

        return $paired;
    }

    /**
     * Drop the raw provider blocks of the paused rows a now-completed turn resumed from.
     *
     * @param string $conversationId Conversation identifier.
     */
    protected function forgetReplayBlocks(string $conversationId): void
    {
        /** @var list<\Crustum\Ai\Model\Entity\ConversationMessage> $paused */
        $paused = $this->messagesTable()
            ->find()
            ->select(['id', 'steps'])
            ->where(['conversation_id' => $conversationId])
            ->where(['status' => MessageStatus::Paused->value])
            ->all()
            ->toList();

        foreach ($paused as $record) {
            $steps = $this->decodedSteps($record);

            if ($steps->every(fn(array $step): bool => $step['replay_blocks'] === [])) {
                continue;
            }

            $record->steps = $this->withoutReplayBlocks($steps)->toList();

            $this->messagesTable()->saveOrFail($record);
        }
    }

    /**
     * Drop the raw provider blocks from the given steps.
     *
     * @param \Cake\Collection\CollectionInterface<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}> $steps Steps.
     * @return \Cake\Collection\CollectionInterface<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function withoutReplayBlocks(CollectionInterface $steps): CollectionInterface
    {
        return $steps->map(fn(array $step): array => [...$step, 'replay_blocks' => []]);
    }

    /**
     * The reasons a response paused on, keyed by tool call ID.
     *
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response.
     * @return \Cake\Collection\CollectionInterface<string, string|null>
     */
    protected function pendingReasonsFor(AgentResponse $response): CollectionInterface
    {
        /** @var \Cake\Collection\CollectionInterface<string, string|null> $reasons */
        $reasons = $response->pendingApprovals->combine(
            fn(PendingApproval $approval): string => $approval->id,
            fn(PendingApproval $approval): ?string => $approval->reason,
        );

        return $reasons;
    }

    /**
     * Decode a stored JSON column.
     *
     * @param mixed $json Stored value.
     * @return array<array-key, mixed>
     */
    protected function decoded(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        $decoded = json_decode((string)($json ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get the tool-call IDs a stored row is still awaiting a decision on.
     *
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $record Message record.
     * @return array<int, string>
     */
    protected function pausedCallIds(ConversationMessage $record): array
    {
        return array_map(
            fn(PendingApproval $approval): string => $approval->id,
            $this->pendingApprovalsIn($record)->toList(),
        );
    }

    /**
     * Get the IDs of a stored row's tool calls that were gated behind an approval.
     *
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $record Message record.
     * @return array<int, string>
     */
    protected function gatedCallIds(ConversationMessage $record): array
    {
        return $this->decodedSteps($record)
            ->unfold(fn(array $step): array => array_values((array)($step['tool_calls'] ?? [])))
            ->filter(fn(mixed $toolCall): bool => is_array($toolCall) && array_key_exists('approval_reason', $toolCall) && isset($toolCall['id']))
            ->map(fn(array $toolCall): string => (string)$toolCall['id'])
            ->toList();
    }

    /**
     * Rebuild the approvals a stored row is still awaiting a decision on.
     *
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $record Message record.
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Approvals\PendingApproval>
     */
    protected function pendingApprovalsIn(ConversationMessage $record): CollectionInterface
    {
        return $this->decodedSteps($record)
            ->unfold(fn(array $step): array => array_values((array)($step['tool_calls'] ?? [])))
            ->filter(fn(mixed $toolCall): bool => is_array($toolCall) && PendingApproval::isPending($toolCall))
            ->map(fn(array $toolCall): PendingApproval => new PendingApproval(
                $toolCall['id'],
                $toolCall['name'],
                $toolCall['arguments'],
                $toolCall['approval_reason'],
            ));
    }

    /**
     * @inheritDoc
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        /** @var list<\Crustum\Ai\Model\Entity\ConversationMessage> $desc */
        $desc = $this->messagesTable()
            ->find()
            ->where(['conversation_id' => $conversationId])
            ->orderByDesc('id')
            ->limit($limit)
            ->all()
            ->toList();

        $records = array_reverse($desc);
        $messages = [];

        foreach ($records as $record) {
            if ($record->role === 'user') {
                $messages[] = $this->userMessageFrom($record);

                continue;
            }

            array_push($messages, ...$this->assistantTurnFrom($record));
        }

        return collection($messages);
    }

    /**
     * Rebuild a stored user turn.
     *
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $record Message record.
     */
    protected function userMessageFrom(ConversationMessage $record): Message
    {
        $attachments = $this->rehydrateAttachments($record->attachments);

        return !$attachments->isEmpty()
            ? new UserMessage((string)$record->content, $attachments)
            : new Message('user', (string)$record->content);
    }

    /**
     * Rebuild a stored assistant turn step by step, so every tool result answers the message that made its call.
     *
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $record Message record.
     * @return list<\Crustum\Ai\Messages\Message>
     */
    protected function assistantTurnFrom(ConversationMessage $record): array
    {
        $pending = $this->pausedCallIds($record);
        $meta = $this->decoded($record->meta);
        $provider = $meta['provider'] ?? null;
        $failed = ($record->status instanceof MessageStatus ? $record->status : MessageStatus::from((string)$record->status)) === MessageStatus::Failed;
        $messages = [];

        foreach ($this->decodedSteps($record)->toList() as $step) {
            $content = $step['content'];
            $replayed = [];

            foreach ($step['tool_calls'] as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }

                if ($failed || PendingApproval::isAnswered($toolCall) || in_array($toolCall['id'] ?? null, $pending, true)) {
                    $replayed[] = $toolCall;
                }
            }

            /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls */
            $toolCalls = collection(array_map(ToolCall::fromArray(...), $replayed));
            /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults */
            $toolResults = collection(array_map(
                fn(array $toolCall): ToolResult => PendingApproval::isAnswered($toolCall)
                    ? ToolResult::fromArray($toolCall)
                    : $this->interruptedResultFor($toolCall),
                array_values(array_filter($replayed, fn(array $toolCall): bool => $failed || PendingApproval::isAnswered($toolCall))),
            ));

            // Raw blocks still name a dropped call, so a step missing one rebuilds generically rather than replaying a call no result answers.
            $replayBlocks = count($replayed) === count($step['tool_calls']) ? $step['replay_blocks'] : [];
            $isBlank = $content === '' && $toolCalls->isEmpty() && $replayBlocks === [];

            if (!$isBlank) {
                $messages[] = new AssistantMessage($content, $toolCalls, $replayBlocks, $provider);
            }

            if (!$toolResults->isEmpty()) {
                $messages[] = new ToolResultMessage($toolResults);
            }
        }

        return $messages;
    }

    /**
     * The result a failed turn's unanswered call replays with.
     *
     * @param array<string, mixed> $toolCall Stored tool call
     */
    protected function interruptedResultFor(array $toolCall): ToolResult
    {
        return new ToolResult(
            $toolCall['id'],
            $toolCall['name'],
            $toolCall['arguments'] ?? [],
            'This tool call was interrupted before a result was recorded, so it may or may not have run.',
        );
    }

    /**
     * Decode a stored row's steps.
     *
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $record Message record.
     * @return \Cake\Collection\CollectionInterface<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function decodedSteps(ConversationMessage $record): CollectionInterface
    {
        /** @var \Cake\Collection\CollectionInterface<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}> $steps */
        $steps = collection($this->decoded($record->steps))->map(fn(mixed $step): array => [
            'content' => (string)($step['content'] ?? ''),
            'tool_calls' => array_values((array)($step['tool_calls'] ?? [])),
            'reasoning' => (string)($step['reasoning'] ?? ''),
            'replay_blocks' => (array)($step['replay_blocks'] ?? []),
            'provider_tool_calls' => array_values((array)($step['provider_tool_calls'] ?? [])),
        ]);

        return $steps;
    }

    /**
     * @inheritDoc
     */
    public function paginateConversationMessages(
        string $conversationId,
        int $perPage = 15,
        string $cursorName = 'cursor',
        ConversationCursor|string|null $cursor = null,
    ): ConversationMessagePage {
        if ($cursor === null) {
            $fromRequest = Router::getRequest()?->getQuery($cursorName);
            $cursor = is_string($fromRequest) && $fromRequest !== '' ? $fromRequest : null;
        }

        if (is_string($cursor)) {
            $cursor = ConversationCursor::decode($cursor);
        }

        $query = $this->messagesTable()
            ->find()
            ->where(['conversation_id' => $conversationId])
            ->orderByDesc('id')
            ->limit($perPage + 1);

        if ($cursor instanceof ConversationCursor) {
            $query->where(['id <' => $cursor->lastId]);
        }

        /** @var list<\Crustum\Ai\Model\Entity\ConversationMessage> $records */
        $records = $query->all()->toList();
        $hasMore = count($records) > $perPage;
        $records = array_slice($records, 0, $perPage);

        $items = array_map(function (ConversationMessage $record): StoredMessage {
            $data = $record->toArray();

            return StoredMessage::fromArray([
                'id' => $data['id'] ?? '',
                'role' => $data['role'] ?? '',
                'content' => $data['content'] ?? '',
                'created' => $data['created'] ?? null,
                'usage' => $data['usage_data'] ?? [],
                'meta' => $data['meta'] ?? [],
                'steps' => $data['steps'] ?? [],
                'status' => $data['status'] ?? null,
                'attachments' => $data['attachments'] ?? [],
            ]);
        }, $records);

        $nextCursor = null;

        if ($hasMore && $items !== []) {
            $nextCursor = new ConversationCursor($items[count($items) - 1]->id);
        }

        return new ConversationMessagePage($items, $nextCursor);
    }

    /**
     * Get the tool calls the given conversation's newest turn is still waiting on.
     *
     * @param string $conversationId Conversation identifier
     * @return list<\Crustum\Ai\Approvals\PendingApproval>
     */
    public function pendingApprovalsFor(string $conversationId): array
    {
        /** @var \Crustum\Ai\Model\Entity\ConversationMessage|null $newest */
        $newest = $this->messagesTable()
            ->find()
            ->where(['conversation_id' => $conversationId])
            ->orderByDesc('id')
            ->first();

        if (!$newest instanceof ConversationMessage || $newest->role !== 'assistant' || MessageStatus::from($newest->status instanceof MessageStatus ? $newest->status->value : (string)$newest->status) !== MessageStatus::Paused) {
            return [];
        }

        return $this->pendingApprovalsIn($newest)->toList();
    }

    /**
     * @inheritDoc
     */
    public function storeApprovalResults(
        string $conversationId,
        array $toolResults,
    ): void {
        if ($toolResults === []) {
            return;
        }

        $resultIds = array_map(fn(ToolResult $result): string => $result->id, $toolResults);

            $messagesTable = $this->messagesTable();

        $messagesTable->getConnection()->transactional(function () use ($messagesTable, $conversationId, $toolResults, $resultIds): void {
            $query = $messagesTable
                ->find()
                ->where(['conversation_id' => $conversationId, 'role' => 'assistant'])
                ->where(['status' => MessageStatus::Paused->value])
                ->orderByDesc('id');

            if (!$messagesTable->getConnection()->getDriver() instanceof Sqlite) {
                $query->epilog('FOR UPDATE');
            }

            $row = null;

            /** @var list<\Crustum\Ai\Model\Entity\ConversationMessage> $candidates */
            $candidates = $query->all()->toList();

            foreach ($candidates as $candidate) {
                if (array_intersect($this->pausedCallIds($candidate), $resultIds) !== []) {
                    $row = $candidate;

                    break;
                }
            }

            if (!$row instanceof ConversationMessage) {
                throw new ApprovalMismatchException(
                    'The approval results do not match a paused conversation turn.',
                    $candidates === [] ? collection([]) : $this->pendingApprovalsIn($candidates[0]),
                );
            }

            $resolved = [];

            foreach ($toolResults as $toolResult) {
                $resolved[$toolResult->id] = $toolResult;
            }

            $steps = [];

            foreach ($this->decodedSteps($row)->toList() as $step) {
                $calls = [];

                foreach ($step['tool_calls'] as $toolCall) {
                    if (!is_array($toolCall)) {
                        $calls[] = $toolCall;

                        continue;
                    }

                    $result = $resolved[$toolCall['id'] ?? ''] ?? null;

                    if ($result === null || PendingApproval::isAnswered($toolCall)) {
                        $calls[] = $toolCall;

                        continue;
                    }

                    // Arguments come along because an edited approval runs the tool with different ones than the call asked for.
                    $calls[] = [...$toolCall, ...array_intersect_key(
                        $result->toArray(),
                        ['arguments' => true, 'result' => true, 'denied' => true, 'failed' => true],
                    )];
                }

                $step['tool_calls'] = $calls;
                $steps[] = $step;
            }

            $pending = [];

            foreach ($this->pendingApprovalsIn($row)->toList() as $approval) {
                $pending[$approval->id] = $approval->reason;
            }

            foreach ($resultIds as $resultId) {
                unset($pending[$resultId]);
            }

            $row->steps = $steps;
            $row->modified = DateTime::now();

            $messagesTable->saveOrFail($row);
        });
    }

    /**
     * @param string $conversationId Conversation identifier.
     * @param \Cake\I18n\DateTime $timestamp Timestamp.
     * @return void
     */
    protected function touchConversation(string $conversationId, DateTime $timestamp): void
    {
        $this->conversationsTable()->updateAll(
            ['modified' => $timestamp],
            ['id' => $conversationId],
        );
    }

    /**
     * @param \Cake\Collection\CollectionInterface<int, mixed> $attachments Message attachments.
     * @return array<int, array<string, mixed>>
     */
    protected function serializeAttachments(CollectionInterface $attachments): array
    {
        return $attachments->map(function (mixed $attachment) {
            if ($attachment instanceof File) {
                if (!method_exists($attachment, 'toArray')) {
                    throw new InvalidArgumentException('Conversation file attachments must be serializable.');
                }

                return $attachment->toArray();
            }

            if (is_array($attachment)) {
                return $attachment;
            }

            throw new InvalidArgumentException('Conversation attachments must be file instances or arrays.');
        })->toList();
    }

    /**
     * @param mixed $attachments Stored attachments value.
     * @return \Cake\Collection\Collection<int, \Crustum\Ai\Files\File>
     */
    protected function rehydrateAttachments(mixed $attachments): Collection
    {
        $decoded = $this->decodeJsonColumn($attachments);

        if (!array_is_list($decoded)) {
            throw new InvalidArgumentException('Stored conversation attachments must be a JSON array.');
        }

        if ($decoded === []) {
            return collection([]);
        }

        $files = collection($decoded)
            ->map(function (mixed $attachment): ?File {
                if (!is_array($attachment)) {
                    throw new InvalidArgumentException('Stored conversation attachment entries must be objects.');
                }

                return File::fromArray($attachment);
            })
            ->filter()
            ->toList();

        return collection($files);
    }

    /**
     * @param mixed $value Stored JSON column value.
     * @return array<int|string, mixed>
     */
    protected function decodeJsonColumn(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return \Crustum\Ai\Model\Table\ConversationsTable
     */
    protected function conversationsTable(): ConversationsTable
    {
        return $this->table('Conversations', ConversationsTable::class, $this->conversationsTableName());
    }

    /**
     * @return \Crustum\Ai\Model\Table\ConversationMessagesTable
     */
    protected function messagesTable(): ConversationMessagesTable
    {
        return $this->table('ConversationMessages', ConversationMessagesTable::class, $this->messagesTableName());
    }

    /**
     * @param string $alias Table alias.
     * @param class-string $className Table class.
     * @param string $tableName Physical table name.
     * @return \Crustum\Ai\Model\Table\ConversationsTable|\Crustum\Ai\Model\Table\ConversationMessagesTable
     */
    protected function table(string $alias, string $className, string $tableName): ConversationsTable|ConversationMessagesTable
    {
        $connection = $this->connection ?? (string)Configure::read('Ai.conversations.connection', 'test');
        $cacheAlias = $alias . '_' . dechex(abs(crc32($connection . ':' . $tableName)));

        /** @var \Crustum\Ai\Model\Table\ConversationsTable|\Crustum\Ai\Model\Table\ConversationMessagesTable $table */
        $table = $this->tableLocator->get($cacheAlias, [
            'className' => 'Crustum/Ai.' . ($alias === 'Conversations' ? 'Conversations' : 'ConversationMessages'),
            'table' => $tableName,
            'connectionName' => $connection,
        ]);

        return $table;
    }

    /**
     * @return string
     */
    protected function conversationsTableName(): string
    {
        return (string)Configure::read('Ai.conversations.tables.conversations', 'agent_conversations');
    }

    /**
     * @return string
     */
    protected function messagesTableName(): string
    {
        return (string)Configure::read('Ai.conversations.tables.messages', 'agent_conversation_messages');
    }
}
