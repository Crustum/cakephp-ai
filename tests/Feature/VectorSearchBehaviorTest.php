<?php
declare(strict_types=1);

use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Cake\Database\Driver\Postgres;
use Cake\Database\Driver\Sqlite;
use Cake\Database\Schema\TableSchema;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Table;
use Crustum\Ai\Embeddings;

function vectorSearchTestTable(array $behaviorConfig = []): Table
{
    $table = new Table(['alias' => 'Documents']);
    $table->addBehavior('Crustum/Ai.VectorSearch', $behaviorConfig);
    $table->setSchema(new TableSchema('documents', [
        'id' => 'integer',
        'title' => 'string',
        'content' => 'text',
        'embedding' => 'string',
    ]));

    return $table;
}

function isPostgres(): bool
{
    $connection = ConnectionManager::get('default');

    return $connection->getDriver() instanceof Postgres;
}

function hasPgvector(): bool
{
    if (!isPostgres()) {
        return false;
    }

    $connection = ConnectionManager::get('default');

    try {
        $result = $connection->execute("SELECT '[1,2,3]'::vector");
        $result->fetchAssoc();

        return true;
    } catch (Throwable) {
        return false;
    }
}

function hasMariaDbVectors(): bool
{
    try {
        $driver = ConnectionManager::get('default')->getDriver();

        if (!$driver instanceof Mysql || !$driver->isMariadb()) {
            return false;
        }

        if (version_compare($driver->version(), '11.7.0', '<')) {
            return false;
        }

        ConnectionManager::get('default')->execute("SELECT VEC_FROMTEXT('[1,2,3]')");

        return true;
    } catch (Throwable) {
        return false;
    }
}

test('behavior declares the similarTo finder', function (): void {
    $table = vectorSearchTestTable();

    expect($table->hasFinder('similarTo'))->toBeTrue();
});

test('similarTo finder builds a pgvector cosine distance query', function (): void {
    $query = vectorSearchTestTable(['dialect' => 'postgres'])->find(
        'similarTo',
        column: 'embedding',
        embedding: [0.1, 0.2, 0.3],
        minSimilarity: 0.7,
    );

    $sql = $query->sql();

    expect($sql)->toContain('<=>')
        ->toContain('::vector')
        ->toContain('[0.1,0.2,0.3]')
        ->toContain('<=')
        ->toContain('0.300000')
        ->toContain('ASC');
});

test('similarTo finder builds a MariaDB native vector query', function (): void {
    $query = vectorSearchTestTable(['dialect' => 'mariadb'])->find(
        'similarTo',
        column: 'embedding',
        embedding: [0.1, 0.2, 0.3],
        minSimilarity: 0.7,
    );

    $sql = $query->sql();

    expect($sql)->toContain('VEC_DISTANCE_COSINE(')
        ->toContain('VEC_FROMTEXT(')
        ->toContain('[0.1,0.2,0.3]')
        ->toContain('0.300000')
        ->toContain('ASC');
});

test('similarTo finder skips ordering when order is false', function (): void {
    $query = vectorSearchTestTable(['dialect' => 'postgres'])->find(
        'similarTo',
        column: 'embedding',
        embedding: [0.1, 0.2, 0.3],
        orderByDistance: false,
    );

    expect($query->sql())->not->toContain('ORDER BY');
});

test('similarTo finder rejects drivers without vector support', function (): void {
    $table = vectorSearchTestTable();
    $table->setConnection(new Connection([
        'driver' => Sqlite::class,
        'database' => ':memory:',
    ]));

    expect(fn(): string => $table->find(
        'similarTo',
        column: 'embedding',
        embedding: [0.1, 0.2, 0.3],
    )->sql())->toThrow(
        RuntimeException::class,
        'Vector distance queries are only supported by Postgres and MariaDB.',
    );
});

test('similarTo finder rejects an unknown dialect', function (): void {
    expect(fn(): string => vectorSearchTestTable(['dialect' => 'mysql'])->find(
        'similarTo',
        column: 'embedding',
        embedding: [0.1, 0.2, 0.3],
    )->sql())->toThrow(InvalidArgumentException::class);
});

test('similarTo finder generates embeddings from a query string', function (): void {
    Embeddings::fake([[[0.9, 0.8]]]);

    $query = vectorSearchTestTable(['dialect' => 'postgres'])->find(
        'similarTo',
        column: 'embedding',
        search: 'best wineries in Napa Valley',
        minSimilarity: 0.4,
    );

    expect($query->sql())->toContain('[0.9,0.8]');
});

test('similarTo finder executes against a pgvector column', function (): void {
    $connection = ConnectionManager::get('default');

    $connection->execute('CREATE TABLE IF NOT EXISTS vector_test_docs (
        id INTEGER PRIMARY KEY,
        title TEXT,
        embedding vector(3)
    )');
    $connection->execute('DELETE FROM vector_test_docs');
    $connection->execute(
        'INSERT INTO vector_test_docs (id, title, embedding) VALUES '
        . "(1, 'First', '[0.1,0.2,0.3]'), "
        . "(2, 'Second', '[0.4,0.5,0.6]')",
    );

    $table = new Table(['alias' => 'VectorTestDocs']);
    $table->setConnection($connection);
    $table->setSchema(new TableSchema('vector_test_docs', [
        'id' => 'integer',
        'title' => 'text',
        'embedding' => 'string',
    ]));
    $table->addBehavior('Crustum/Ai.VectorSearch');

    $results = $table->find(
        'similarTo',
        column: 'embedding',
        embedding: [0.1, 0.2, 0.3],
        minSimilarity: 0.0,
    )
        ->select(['id', 'title'])
        ->all();

    expect($results->count())->toBeGreaterThanOrEqual(1);

    $connection->execute('DROP TABLE vector_test_docs');
})->skip(!hasPgvector(), 'pgvector extension is required for execution tests.');

test('similarTo finder executes against a MariaDB native vector column', function (): void {
    $connection = ConnectionManager::get('default');

    $connection->execute('CREATE TABLE IF NOT EXISTS vector_test_docs_maria (
        id INT PRIMARY KEY,
        title TEXT,
        embedding VECTOR(3)
    )');

    try {
        $connection->execute('DELETE FROM vector_test_docs_maria');
        $connection->execute(
            'INSERT INTO vector_test_docs_maria (id, title, embedding) VALUES '
            . "(1, 'First', VEC_FROMTEXT('[0.1,0.2,0.3]')), "
            . "(2, 'Second', VEC_FROMTEXT('[0.4,0.5,0.6]'))",
        );

        $table = new Table(['alias' => 'VectorTestDocsMaria']);
        $table->setConnection($connection);
        $table->setSchema(new TableSchema('vector_test_docs_maria', [
            'id' => 'integer',
            'title' => 'text',
            'embedding' => 'string',
        ]));
        $table->addBehavior('Crustum/Ai.VectorSearch');

        $results = $table->find(
            'similarTo',
            column: 'embedding',
            embedding: [0.1, 0.2, 0.3],
            minSimilarity: 0.0,
        )
            ->select(['id', 'title'])
            ->all();

        expect($results->count())->toBe(2)
            ->and($results->first()->get('title'))->toBe('First');
    } finally {
        $connection->execute('DROP TABLE IF EXISTS vector_test_docs_maria');
    }
})->skip(!hasMariaDbVectors(), 'MariaDB 11.7+ native vector support is required for execution tests.');
