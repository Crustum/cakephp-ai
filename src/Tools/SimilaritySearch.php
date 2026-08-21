<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Closure;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Support\CollectionReranker;
use Crustum\JsonSchema\Contracts\JsonSchema;
use InvalidArgumentException;
use Stringable;

/**
 * Similarity search tool backed by a custom query closure.
 */
class SimilaritySearch implements Tool
{
    protected ?string $description = null;

    protected bool $rerank = false;

    protected Closure|array|string|null $rerankBy = null;

    protected ?int $rerankLimit = null;

    /**
     * @param \Closure(string): mixed $using Search callback.
     */
    public function __construct(public Closure $using)
    {
    }

    /**
     * Create a new similarity search tool instance.
     *
     * The given model is resolved through the table locator, so it may be a
     * CakePHP table alias or a table class name. The table must expose the
     * `similarTo` custom finder provided by the `Crustum/Ai.VectorSearch`
     * behavior.
     *
     * @param \Cake\ORM\Table|string $model Table, table alias or table class name.
     * @param string $column Vector column name.
     * @param float $minSimilarity Minimum similarity threshold.
     * @param int $limit Maximum number of results.
     * @param \Closure|null $query Optional query modifier closure.
     * @throws \InvalidArgumentException if the given model class name or vector column name is blank.
     */
    public static function usingModel(
        Table|string $model,
        string $column,
        float $minSimilarity = 0.6,
        int $limit = 15,
        ?Closure $query = null,
    ): self {
        if (is_string($model) && blank($model)) {
            throw new InvalidArgumentException('A model class name is required for similarity search.');
        }

        if (blank($column)) {
            throw new InvalidArgumentException('A vector column name is required for similarity search.');
        }

        $table = is_string($model)
            ? TableRegistry::getTableLocator()->get($model)
            : $model;

        return new self(function (string $queryString) use ($table, $column, $minSimilarity, $limit, $query): Collection {
            $pendingQuery = $table->find('similarTo', [
                'column' => $column,
                'search' => $queryString,
                'minSimilarity' => $minSimilarity,
            ]);

            if ($query instanceof Closure) {
                $pendingQuery = $query($pendingQuery);
            }

            if ($limit !== 0) {
                $pendingQuery->limit($limit);
            }

            return new Collection(
                $pendingQuery
                    ->all()
                    ->map(fn($model): array => array_diff_key($model->toArray(), array_flip([$column])))
                    ->toList(),
            );
        });
    }

    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): Stringable|string
    {
        return $this->description ?? 'Search for documents similar to a given query.';
    }

    /**
     * Execute the tool.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request.
     * @return string
     */
    public function handle(Request $request): string
    {
        $results = call_user_func($this->using, $request->string('query'));

        $results = $results instanceof CollectionInterface
            ? $results
            : new Collection($results);

        if ($results->isEmpty()) {
            return 'No relevant results found.';
        }

        if ($this->rerank) {
            $results = CollectionReranker::rerank(
                $results,
                $this->rerankBy,
                $request->string('query'),
                $this->rerankLimit,
            );
        }

        return "Relevant results found. They are listed below in order of relevance:\n\n"
            . json_encode($results->toList(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Set the tool's description.
     *
     * @param string $description Tool description.
     */
    public function withDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Indicate that the results should be reranked.
     *
     * @param \Closure|array<int, string>|string $by Field resolver.
     * @param int|null $limit Result limit.
     */
    public function rerank(Closure|array|string $by, ?int $limit = null): static
    {
        $this->rerank = true;
        $this->rerankBy = $by;
        $this->rerankLimit = $limit;

        return $this;
    }

    /**
     * Get the tool's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema Schema builder.
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('The search query.')
                ->required(),
        ];
    }
}
