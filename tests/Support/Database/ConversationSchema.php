<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Database;

use Cake\Core\Configure;

/**
 * Conversation test helpers.
 *
 * Table DDL lives in tests/schema.php and is loaded once from bootstrap
 * for `test` and `secondary`.
 */
class ConversationSchema
{
    /**
     * Point conversation config at a connection (schema already exists from bootstrap).
     *
     * @param string|null $connection Connection name.
     * @return void
     */
    public static function create(?string $connection = null): void
    {
        if ($connection !== null) {
            Configure::write('Ai.conversations.connection', $connection);
        }
    }

    /**
     * Persist a conversation message row through the ORM.
     *
     * @param array<string, mixed> $data Message attributes.
     * @param string|null $connection Connection name.
     * @return void
     */
    public static function saveMessage(array $data, ?string $connection = null): void
    {
        if (
            !array_key_exists('steps', $data)
            && (array_key_exists('tool_calls', $data) || array_key_exists('tool_results', $data))
        ) {
            $toolCalls = (array)($data['tool_calls'] ?? []);
            $toolResults = (array)($data['tool_results'] ?? []);
            $replayBlocks = (array)($data['replay_blocks'] ?? []);
            $results = [];

            foreach ($toolResults as $result) {
                $results[$result['id']] = $result;
            }

            $calls = array_map(
                fn(array $call): array => array_merge(
                    $call,
                    array_intersect_key($results[$call['id']] ?? [], ['result' => true, 'denied' => true, 'failed' => true]),
                ),
                $toolCalls,
            );

            // One entry per model round-trip: the tool step carries the calls,
            // and trailing text lives on its own step like TextGenerationLoop writes.
            $steps = [
                [
                    'content' => $calls === [] ? (string)($data['content'] ?? '') : '',
                    'tool_calls' => $calls,
                    'replay_blocks' => $replayBlocks,
                ],
            ];

            if ($calls !== [] && (string)($data['content'] ?? '') !== '') {
                $steps[] = [
                    'content' => (string)$data['content'],
                    'tool_calls' => [],
                    'replay_blocks' => [],
                ];
            }

            unset($data['tool_calls'], $data['tool_results'], $data['replay_blocks']);
            $data['steps'] = $steps;
        }

        foreach (['attachments', 'steps', 'usage_data', 'meta'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $decoded = json_decode($data[$field], true);
                $data[$field] = is_array($decoded) ? $decoded : [];
            }
        }

        if (array_key_exists('usage', $data) && !array_key_exists('usage_data', $data)) {
            $data['usage_data'] = is_string($data['usage']) ? (json_decode($data['usage'], true) ?? []) : $data['usage'];
            unset($data['usage']);
        }

        $options = $connection !== null ? ['connectionName' => $connection] : [];
        $messagesTable = test()->getTableLocator()->get('Crustum/Ai.ConversationMessages', $options);
        $messagesTable->saveOrFail($messagesTable->newEntity($data));
    }
}
