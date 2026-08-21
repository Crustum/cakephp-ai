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
        $options = $connection !== null ? ['connectionName' => $connection] : [];
        $messagesTable = test()->getTableLocator()->get('Crustum/Ai.ConversationMessages', $options);
        $messagesTable->saveOrFail($messagesTable->newEntity($data));
    }
}
