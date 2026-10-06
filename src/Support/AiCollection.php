<?php
declare(strict_types=1);

namespace Crustum\Ai\Support;

use Cake\Collection\Collection;
use Cake\Utility\Hash;
use Closure;
use Crustum\Ai\Classification\CollectionChoice;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Reranking;
use Crustum\Ai\Responses\Data\RankedDocument;

/**
 * AI-enriched collection.
 *
 * Extends the Cake collection with AI-backed helpers.
 *
 * @template TKey
 * @template TValue
 * @extends \Cake\Collection\Collection<TKey, TValue>
 */
final class AiCollection extends Collection
{
    /**
     * Rerank the collection items against the given query.
     *
     * Items are returned in reranked order.
     *
     * @param string $query Reranking query
     * @param \Closure|array<int, string>|string|null $by Field resolver, null when items are already strings
     * @param int|null $limit Result limit
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param string|null $model Model name
     * @param int $timeout Timeout in seconds
     * @return static<int, TValue>
     */
    public function rerank(
        string $query,
        Closure|array|string|null $by = null,
        ?int $limit = null,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        int $timeout = 30,
    ): static {
        $resolver = match (true) {
            $by === null => fn(mixed $item): string => (string)$item,
            $by instanceof Closure => $by,
            is_array($by) => function (mixed $item) use ($by): string {
                $fields = [];

                foreach ($by as $field) {
                    $fields[$field] = Hash::get($item, $field);
                }

                return json_encode($fields) ?: '';
            },
            default => fn(mixed $item): mixed => Hash::get($item, $by),
        };

        $items = $this->toList();

        $response = Reranking::of($this->map($resolver)->toList())
            ->limit($limit)
            ->timeout($timeout)
            ->rerank($query, $provider, $model);

        return new static(
            array_map(
                fn(RankedDocument $result): mixed => $items[$result->index],
                $response->results,
            ),
        );
    }

    /**
     * Choose the item that best answers the question about the given text.
     *
     * @param string $question Classification question
     * @param array<string, mixed>|string $text Text to classify
     * @param \Closure(mixed): mixed|string|null $by Field or closure that names each item
     * @param \Closure(mixed): mixed|array<int, string>|string|null $describe Field, fields, or closure that describe each item
     * @param float|null $threshold Minimum probability of the chosen option; below it, null is returned
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param string|null $model Model name
     * @param int|null $timeout Request timeout in seconds
     * @return mixed
     */
    public function decide(
        string $question,
        string|array $text,
        Closure|string|null $by = null,
        Closure|array|string|null $describe = null,
        ?float $threshold = null,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): mixed {
        return (new CollectionChoice($this, $by, $describe))
            ->decide($question, $text, $threshold, $provider, $model, $timeout);
    }
}
