<?php
declare(strict_types=1);

namespace Crustum\Ai\Model\Behavior;

use Cake\ORM\Behavior;
use Cake\ORM\Query\SelectQuery;
use Crustum\Ai\Embeddings;
use JsonException;
use RuntimeException;

/**
 * Adds a pgvector `similarTo` custom finder to a table.
 *
 * Attach this behavior to any table that stores vector embeddings in a
 * PostgreSQL `vector` column (via the pgvector extension), then search for
 * similar records using `find('similarTo', ...)`.
 *
 * Usage:
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
    ];

    /**
     * Filter and order a query by cosine similarity against a vector column.
     *
     * Pass either an `embedding` (array of floats) or a `search` string. When a
     * search string is given, embeddings are generated automatically via the
     * `Embeddings` facade.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify.
     * @param string $column The vector column name.
     * @param float $minSimilarity Minimum cosine similarity (0.0 - 1.0).
     * @param string|null $search Text to embed and search for.
     * @param array<int, float|int>|null $embedding Pre-computed embedding vector.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findSimilarTo(
        SelectQuery $query,
        string $column = 'embedding',
        float $minSimilarity = 0.6,
        ?string $search = null,
        ?array $embedding = null,
    ): SelectQuery {
        if ($embedding === null) {
            $embedding = $this->embeddingFor((string)$search);
        }

        $vector = $this->vectorLiteral($embedding);
        $similarity = sprintf('%s <=> %s::vector', $column, $vector);

        return $query
            ->where(sprintf('%s >= %F', $similarity, $minSimilarity))
            ->orderByDesc($similarity);
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
