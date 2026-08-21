<?php
declare(strict_types=1);

use Cake\Database\Driver\Postgres;
use Cake\Database\Schema\TableSchema;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Table;
use Crustum\Ai\Embeddings;

function vectorSearchTestTable(): Table
{
    $table = new Table(['alias' => 'Documents']);
    $table->addBehavior('Crustum/Ai.VectorSearch');
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

test('behavior declares the similarTo finder', function (): void {
    $table = vectorSearchTestTable();

    expect($table->hasFinder('similarTo'))->toBeTrue();
});

test('similarTo finder builds a pgvector cosine similarity query', function (): void {
    $query = vectorSearchTestTable()->find('similarTo', [
        'column' => 'embedding',
        'embedding' => [0.1, 0.2, 0.3],
        'minSimilarity' => 0.7,
    ]);

    $sql = $query->sql();

    expect($sql)->toContain('embedding <=>')
        ->toContain('::vector')
        ->toContain('[0.1,0.2,0.3]')
        ->toContain('0.700000');
});

test('similarTo finder generates embeddings from a query string', function (): void {
    Embeddings::fake([[[0.9, 0.8]]]);

    $query = vectorSearchTestTable()->find('similarTo', [
        'column' => 'embedding',
        'search' => 'best wineries in Napa Valley',
        'minSimilarity' => 0.4,
    ]);

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

    $results = $table->find('similarTo', [
        'column' => 'embedding',
        'embedding' => [0.1, 0.2, 0.3],
        'minSimilarity' => 0.0,
    ])
        ->select(['id', 'title'])
        ->all();

    expect($results->count())->toBeGreaterThanOrEqual(1);

    $connection->execute('DROP TABLE vector_test_docs');
})->skip(!hasPgvector(), 'pgvector extension is required for execution tests.');
