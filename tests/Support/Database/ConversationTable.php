<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Database;

use Cake\Core\Configure;
use Cake\Database\Query;
use Cake\Datasource\ConnectionManager;

/**
 * Test helper for conversation database operations.
 */
class ConversationTable
{
    /**
     * Get a query for a database table.
     *
     * @param string $table Table name.
     * @param string|null $connection Connection name.
     * @return \Cake\Database\Query
     */
    public static function query(string $table, ?string $connection = null): Query
    {
        $connection ??= (string)Configure::read('Ai.conversations.connection', 'test');

        return ConnectionManager::get($connection)->selectQuery()->select('*')->from($table);
    }

    /**
     * Fetch the first matching row as an object.
     *
     * @param string $table Table name.
     * @param array<string, mixed> $conditions Conditions.
     * @param string|null $connection Connection name.
     */
    public static function first(string $table, array $conditions = [], ?string $connection = null): ?object
    {
        $row = static::query($table, $connection)->where($conditions)->limit(1)->execute()->fetch('assoc');

        return $row === false ? null : (object)$row;
    }

    /**
     * Determine if a row exists for the given table conditions.
     *
     * @param string $table Table name.
     * @param array<string, mixed> $conditions Conditions.
     * @param string|null $connection Connection name.
     * @return bool
     */
    public static function exists(string $table, array $conditions, ?string $connection = null): bool
    {
        return static::first($table, $conditions, $connection) !== null;
    }

    /**
     * Insert a row into the given table.
     *
     * @param string $table Table name.
     * @param array<string, mixed> $data Row data.
     * @param string|null $connection Connection name.
     * @return void
     */
    public static function insert(string $table, array $data, ?string $connection = null): void
    {
        ConnectionManager::get($connection ?? (string)Configure::read('Ai.conversations.connection', 'test'))
            ->insertQuery($table, $data)
            ->execute();
    }
}
