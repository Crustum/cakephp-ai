<?php
declare(strict_types=1);

namespace Crustum\Ai\Storage;

use Cake\Collection\Collection;
use Cake\Core\Configure;
use Cake\Database\Driver\Sqlite;
use Cake\I18n\FrozenTime;
use Cake\ORM\Locator\TableLocator;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Contracts\ConversationStore;
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
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Utility\Uuid;
use Crustum\Ai\Utility\Value;
use InvalidArgumentException;

/**
 * Database-backed conversation store.
 */
class DatabaseConversationStore implements ConversationStore
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
    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        /** @var \Crustum\Ai\Model\Entity\Conversation|null $record */
        $record = $this->conversationsTable()
            ->find()
            ->select(['id'])
            ->where([
                'participant_type' => $participantType,
                'participant_id' => (string)$participantId,
            ])
            ->order(['modified' => 'DESC'])
            ->first();

        return $record?->id;
    }

    /**
     * @inheritDoc
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        $conversationId = Uuid::v7();
        $now = FrozenTime::now();

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
        AgentPrompt $prompt,
    ): string {
        $messageId = Uuid::v7();
        $now = FrozenTime::now();

        $this->messagesTable()->saveOrFail($this->messagesTable()->newEntity([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId === null ? null : (string)$participantId,
            'agent' => $prompt->agent::class,
            'role' => 'user',
            'content' => $prompt->prompt,
            'attachments' => $this->serializeAttachments($prompt),
            'tool_calls' => [],
            'tool_results' => [],
            'usage_data' => [],
            'meta' => [],
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
    ): ?string {
        $messageId = Uuid::v7();
        $now = FrozenTime::now();

        $toolResults = $response->toolResults->toList();

        if ($prompt->hasApprovalDecisions()) {
            $existing = $this->existingToolResultIds($conversationId);

            $toolResults = array_values(array_filter(
                $toolResults,
                fn(ToolResult $result): bool => !in_array($result->id, $existing, true),
            ));

            if (Value::blank($response->text) && $response->toolCalls->isEmpty() && $toolResults === []) {
                return null;
            }
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
            'tool_calls' => $response->toolCalls
                ->map(fn(ToolCall $call): array => $call->toArray())
                ->toList(),
            'tool_results' => array_map(
                fn(ToolResult $result): array => $result->toArray(),
                $toolResults,
            ),
            'usage_data' => $response->usage->toArray(),
            'meta' => $this->messageMeta($response),
            'approval_state' => $this->approvalState($response),
            'created' => $now,
            'modified' => $now,
        ]));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Get every tool-result ID recorded on the conversation's approval-paused rows.
     *
     * @param string $conversationId Conversation identifier.
     * @return array<int, string>
     */
    protected function existingToolResultIds(string $conversationId): array
    {
        /** @var list<\Crustum\Ai\Model\Entity\ConversationMessage> $records */
        $records = $this->messagesTable()
            ->find()
            ->where(['conversation_id' => $conversationId, 'role' => 'assistant'])
            ->where(fn($exp) => $exp->isNotNull('approval_state'))
            ->all()
            ->toList();

        $ids = [];

        foreach ($records as $record) {
            foreach ($this->decodeJsonColumn($record->tool_results) as $result) {
                if (is_array($result) && isset($result['id'])) {
                    $ids[] = $result['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * Mark a paused assistant row with the tool-call IDs pending a decision, or null when the turn is not a pause.
     *
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response.
     * @return string|null
     */
    protected function approvalState(AgentResponse $response): ?string
    {
        if (!$response->hasPendingApprovals()) {
            return null;
        }

        $pending = [];

        foreach ($response->pendingApprovals as $approval) {
            $pending[$approval->id] = $approval->reason;
        }

        return json_encode(['pending' => $pending]);
    }

    /**
     * Get the tool-call IDs a stored row recorded as pending a decision.
     *
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $record Message record.
     * @return array<int, string>
     */
    protected function pausedCallIds(ConversationMessage $record): array
    {
        $state = json_decode((string)($record->approval_state ?? 'null'), true);

        if (is_array($state) && is_array($state['pending'] ?? null)) {
            return array_map(strval(...), array_keys($state['pending']));
        }

        return [];
    }

    /**
     * Build the message meta payload, tucking a paused turn's raw provider blocks alongside the response meta.
     *
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response.
     * @return array<string, mixed>
     */
    protected function messageMeta(AgentResponse $response): array
    {
        $meta = $response->meta->toArray();

        $blocks = $response->pausedProviderContentBlocks();

        if (Value::filled($blocks)) {
            $meta['provider_content_blocks'] = $blocks;
        }

        return $meta;
    }

    /**
     * @inheritDoc
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        /** @var list<\Crustum\Ai\Model\Entity\ConversationMessage> $records */
        $records = $this->messagesTable()
            ->find()
            ->where(['conversation_id' => $conversationId])
            ->order(['id' => 'ASC'])
            ->limit($limit)
            ->all()
            ->toList();

        $resolvedCallIds = [];

        foreach ($records as $record) {
            foreach ($this->decodeJsonColumn($record->tool_results) as $result) {
                if (is_array($result) && isset($result['id']) && filled($result['id'])) {
                    $resolvedCallIds[] = $result['id'];
                }
            }
        }

        return collection($this->mapRecordsToMessages($records, $resolvedCallIds));
    }

    /**
     * @param list<\Crustum\Ai\Model\Entity\ConversationMessage> $records Message records.
     * @param array<int, string> $resolvedCallIds Ids of calls answered anywhere in the window.
     * @return list<\Crustum\Ai\Messages\Message>
     */
    protected function mapRecordsToMessages(array $records, array $resolvedCallIds = []): array
    {
        $messages = [];

        foreach ($records as $record) {
            $toolCalls = array_values($this->decodeJsonColumn($record->tool_calls));
            $toolResults = array_values($this->decodeJsonColumn($record->tool_results));

            if ($record->role === 'user') {
                $attachments = $this->rehydrateAttachments($record->attachments);

                if (!$attachments->isEmpty()) {
                    $messages[] = new UserMessage((string)$record->content, $attachments);

                    continue;
                }

                $messages[] = new Message('user', (string)$record->content);

                continue;
            }

            if ($toolCalls !== []) {
                array_push($messages, ...$this->reconstructToolTurn($record, $toolCalls, $toolResults, $resolvedCallIds));

                continue;
            }

            if ($toolResults !== []) {
                $messages[] = new ToolResultMessage(
                    collection(array_map(ToolResult::fromArray(...), $toolResults)),
                );

                if (!Value::blank($record->content)) {
                    $messages[] = new AssistantMessage((string)$record->content);
                }

                continue;
            }

            $messages[] = new AssistantMessage((string)$record->content);
        }

        $offset = 0;

        foreach ($messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                break;
            }

            $offset++;
        }

        return array_slice($messages, $offset);
    }

    /**
     * Rebuild the messages for a stored assistant turn that made tool calls, keeping a pause distinct from a completed turn.
     *
     * @param \Crustum\Ai\Model\Entity\ConversationMessage $record Message record.
     * @param array<int, array<string, mixed>> $toolCalls Stored tool calls.
     * @param array<int, array<string, mixed>> $toolResults Stored tool results.
     * @param array<int, string> $resolvedCallIds Ids of calls answered anywhere in the window.
     * @return list<\Crustum\Ai\Messages\Message>
     */
    protected function reconstructToolTurn(ConversationMessage $record, array $toolCalls, array $toolResults, array $resolvedCallIds = []): array
    {
        $callIds = array_column($toolCalls, 'id');

        $priorResults = array_values(array_filter(
            $toolResults,
            fn(array $result): bool => !in_array($result['id'], $callIds, true),
        ));
        $ownResults = array_values(array_filter(
            $toolResults,
            fn(array $result): bool => in_array($result['id'], $callIds, true),
        ));

        $resultsById = array_column($ownResults, null, 'id');

        $resolvedCalls = array_values(array_filter(
            $toolCalls,
            fn(array $call): bool => filled($call['id'] ?? null) && array_key_exists($call['id'], $resultsById),
        ));
        $pendingCalls = array_values(array_filter(
            $toolCalls,
            fn(array $call): bool => !filled($call['id'] ?? null) || !array_key_exists($call['id'], $resultsById),
        ));

        $ownResults = array_map(
            fn(array $call): array => $resultsById[$call['id']],
            $resolvedCalls,
        );

        $pausedCallIds = $this->pausedCallIds($record);

        $isPause = $pendingCalls !== []
            && array_diff(array_column($pendingCalls, 'id'), $pausedCallIds) === [];

        $messages = [];

        if ($priorResults !== []) {
            $messages[] = new ToolResultMessage(
                collection(array_map(ToolResult::fromArray(...), $priorResults)),
            );
        }

        $meta = $this->decodeJsonColumn($record->meta);
        $providerContentBlocks = $meta['provider_content_blocks'] ?? [];

        if ($isPause && Value::filled($providerContentBlocks)) {
            $messages[] = new AssistantMessage(
                (string)$record->content,
                collection(array_map(ToolCall::fromArray(...), $toolCalls)),
                $providerContentBlocks,
                $meta['provider'] ?? null,
            );

            if ($ownResults !== []) {
                $messages[] = new ToolResultMessage(
                    collection(array_map(ToolResult::fromArray(...), $ownResults)),
                );
            }

            return $messages;
        }

        if ($resolvedCalls !== []) {
            $messages[] = new AssistantMessage(
                '',
                collection(array_map(ToolCall::fromArray(...), $resolvedCalls)),
            );
            $messages[] = new ToolResultMessage(
                collection(array_map(ToolResult::fromArray(...), $ownResults)),
            );
        }

        $keptCalls = array_values(array_filter(
            $pendingCalls,
            fn(array $call): bool => in_array($call['id'], $pausedCallIds, true)
                || in_array($call['id'], $resolvedCallIds, true),
        ));

        if ($keptCalls !== []) {
            $messages[] = new AssistantMessage(
                (string)$record->content,
                collection(array_map(ToolCall::fromArray(...), $keptCalls)),
            );
        } elseif (!Value::blank($record->content)) {
            $messages[] = new AssistantMessage((string)$record->content);
        }

        return $messages;
    }

    /**
     * @inheritDoc
     */
    public function storeApprovalResults(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        array $toolResults,
    ): void {
        if ($toolResults === []) {
            return;
        }

        $resultIds = array_map(fn(ToolResult $result): string => $result->id, $toolResults);

        $messagesTable = $this->messagesTable();

        $messagesTable->getConnection()->transactional(function () use ($messagesTable, $conversationId, $participantType, $participantId, $toolResults, $resultIds): void {
            $query = $messagesTable
                ->find()
                ->where(['conversation_id' => $conversationId, 'role' => 'assistant'])
                ->where(fn($exp) => $exp->isNotNull('approval_state'))
                ->order(['id' => 'DESC']);

            if ($participantType === null) {
                $query->where(fn($exp) => $exp->isNull('participant_type'));
            } else {
                $query->where(['participant_type' => $participantType]);
            }

            if ($participantId === null) {
                $query->where(fn($exp) => $exp->isNull('participant_id'));
            } else {
                $query->where(['participant_id' => (string)$participantId]);
            }

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
                    collection([]),
                );
            }

            $existing = array_values($this->decodeJsonColumn($row->tool_results));
            $existingIds = array_column($existing, 'id');

            $additional = array_map(
                fn(ToolResult $result): array => $result->toArray(),
                array_values(array_filter(
                    $toolResults,
                    fn(ToolResult $result): bool => !in_array($result->id, $existingIds, true),
                )),
            );

            $merged = array_merge($existing, $additional);

            $state = json_decode((string)($row->approval_state ?? 'null'), true);
            $pending = is_array($state) && is_array($state['pending'] ?? null) ? $state['pending'] : [];

            foreach ($resultIds as $resultId) {
                unset($pending[$resultId]);
            }

            $row->tool_results = $merged;
            $row->approval_state = json_encode(['pending' => $pending]);
            $row->modified = FrozenTime::now();

            $messagesTable->saveOrFail($row);
        });
    }

    /**
     * @param string $conversationId Conversation identifier.
     * @param \Cake\I18n\FrozenTime $timestamp Timestamp.
     * @return void
     */
    protected function touchConversation(string $conversationId, FrozenTime $timestamp): void
    {
        $this->conversationsTable()->updateAll(
            ['modified' => $timestamp],
            ['id' => $conversationId],
        );
    }

    /**
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt.
     * @return array<int, array<string, mixed>>
     */
    protected function serializeAttachments(AgentPrompt $prompt): array
    {
        return $prompt->attachments->map(function (mixed $attachment) {
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
