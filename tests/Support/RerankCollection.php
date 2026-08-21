<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support;

use Cake\Collection\Collection;
use Cake\Utility\Hash;
use Closure;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Reranking;
use Crustum\Ai\Responses\Data\RankedDocument;

/**
 * Rerank collection helper for integration tests.
 */
class RerankCollection
{
    /**
     * Rerank a collection of items using a field resolver.
     *
     * @param \Cake\Collection\Collection<int|string, mixed> $items Items to rerank.
     * @param \Closure|array<int, string>|string $by Field resolver.
     * @param string $query Reranking query.
     * @param int|null $limit Result limit.
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider.
     * @param string|null $model Model name.
     * @return \Cake\Collection\Collection<int, mixed>
     */
    public static function rerank(
        Collection $items,
        Closure|array|string $by,
        string $query,
        ?int $limit = null,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): Collection {
        $resolver = match (true) {
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

        $response = Reranking::of($items->map($resolver)->toList())
            ->limit($limit)
            ->rerank($query, $provider, $model);

        return collection(
            collection($response->results)->map(
                fn(RankedDocument $result): mixed => $items->toList()[$result->index],
            )->toList(),
        );
    }
}
