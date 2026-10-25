<?php
declare(strict_types=1);

namespace Crustum\Ai\Model\Behavior;

use Cake\Database\Driver\Mysql;
use Cake\Database\Driver\Postgres;
use Cake\ORM\Behavior;
use Cake\ORM\Query\SelectQuery;
use Crustum\Ai\Embeddings;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Adds a vector `similarTo` custom finder to a table.
 *
 * Provides `whereVectorSimilarTo()`-style vector similarity filtering for the
 * query builder, supported on PostgreSQL via the `pgvector` extension and on
 * MariaDB 11.7+ via its native vector support.
 *
 * Attach this behavior to any table that stores vector embeddings in a
 * `vector` column, then search for similar records using
 * `find('similarTo', ...)`:
 *
 * ```
 * public function initialize(array $config): void
 * {
 *     $this->addBehavior('Crustum/Ai.VectorSearch');
 * }
 *
 * $results = $this->Documents->find('similarTo',
 *     column: 'embedding',
 *     search: 'best wineries in Napa Valley',
 *     minSimilarity: 0.4,
 * )->limit(10)->all();
 * ```
 *
 * The SQL dialect is detected from the query connection automatically. Set the
 * `dialect` behavior option to `postgres` or `mariadb` to override detection
 * (useful for building SQL without a live connection):
 *
 * ```
 * $this->addBehavior('Crustum/Ai.VectorSearch', ['dialect' => 'mariadb']);
 * ```
 */
class VectorSearchBehavior extends Behavior
{
    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'implementedFinders' => [
            'similarTo' => 'findSimilarTo',
        ],
        'dialect' => 'auto',
    ];

    /**
     * Filter and order a query by cosine similarity against a vector column.
     *
     * Like `whereVectorSimilarTo()`, this filters rows whose cosine
     * distance is below `1 - $minSimilarity` and orders the remaining rows by
     * ascending distance (most similar first).
     *
     * Pass either an `embedding` (array of floats) or a `search` string. When a
     * search string is given, embeddings are generated automatically via the
     * `Embeddings` facade.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to modify.
     * @param string $column The vector column name.
     * @param float $minSimilarity Minimum cosine similarity (0.0 - 1.0).
     * @param string|null $search Text to embed and search for.
     * @param array<int, float|int>|null $embedding Pre-computed embedding vector.
     * @param bool $orderByDistance Whether to order results by ascending vector distance.
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    public function findSimilarTo(
        SelectQuery $query,
        string $column = 'embedding',
        float $minSimilarity = 0.6,
        ?string $search = null,
        ?array $embedding = null,
        bool $orderByDistance = true,
    ): SelectQuery {
        $embedding ??= $this->embeddingFor((string)$search);

        $distance = $this->distanceExpression($query, $column, $embedding);
        $maxDistance = 1 - $minSimilarity;

        $query->where(sprintf('%s <= %F', $distance, $maxDistance));

        if ($orderByDistance) {
            $query->orderByAsc($distance);
        }

        return $query;
    }

    /**
     * Build the cosine distance expression for the query connection dialect.
     *
     * Compiles the per-dialect cosine distance expression: `(column <=> ?)`
     * for pgvector and `vec_distance_cosine(column, vec_fromtext(?))` for
     * MariaDB 11.7+.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to modify.
     * @param string $column The vector column name.
     * @param array<int, float|int> $embedding Embedding values.
     * @return string
     */
    protected function distanceExpression(SelectQuery $query, string $column, array $embedding): string
    {
        $quoted = $query->getConnection()->getDriver()->quoteIdentifier($column);
        $literal = $this->vectorLiteral($embedding);

        if ($this->dialectFor($query) === 'mariadb') {
            return sprintf('VEC_DISTANCE_COSINE(%s, VEC_FROMTEXT(%s))', $quoted, $literal);
        }

        return sprintf('(%s <=> %s::vector)', $quoted, $literal);
    }

    /**
     * Resolve the vector SQL dialect for the given query.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to modify.
     * @return string
     */
    protected function dialectFor(SelectQuery $query): string
    {
        $dialect = $this->getConfig('dialect');

        if ($dialect === 'postgres' || $dialect === 'mariadb') {
            return $dialect;
        }

        if ($dialect !== 'auto') {
            throw new InvalidArgumentException(
                sprintf('Invalid VectorSearch dialect [%s]. Expected "auto", "postgres" or "mariadb".', (string)$dialect),
            );
        }

        $driver = $query->getConnection()->getDriver();

        if ($driver instanceof Postgres) {
            return 'postgres';
        }

        if ($driver instanceof Mysql && $driver->isMariadb()) {
            return 'mariadb';
        }

        throw new RuntimeException('Vector distance queries are only supported by Postgres and MariaDB.');
    }

    /**
     * Generate an embedding for the given search query.
     *
     * @param string $query Search query.
     * @return array<int, float|int>
     */
    protected function embeddingFor(string $query): array
    {
        return Embeddings::for([$query])->generate()->embeddings[0];
    }

    /**
     * Quote a float vector as a pgvector literal.
     *
     * Embedding values are cast to floats, so the resulting JSON array contains
     * only `[0-9.eE+-]` characters and is safe to inline into raw SQL.
     *
     * @param array<int, mixed> $embedding Embedding values.
     * @return string
     */
    protected function vectorLiteral(array $embedding): string
    {
        try {
            $values = array_map(
                static fn(mixed $value): string => json_encode((float)$value, JSON_THROW_ON_ERROR),
                $embedding,
            );
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to encode vector embedding.', 0, $jsonException);
        }

        return "'[" . implode(',', $values) . "]'";
    }
}
