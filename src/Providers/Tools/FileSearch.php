<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Tools;

use Closure;
use Crustum\Ai\Store;

/**
 * File search tool.
 *
 * Enables searching across vector stores with optional metadata filtering.
 */
class FileSearch extends ProviderTool
{
    /**
     * The file search filters.
     *
     * @var array<int, array{type: string, key: string, value: mixed}>
     */
    public array $filters = [];

    /**
     * Create a new file search tool instance.
     *
     * @param array<\Crustum\Ai\Store|string> $stores Store instances or IDs
     * @param \Closure(\Crustum\Ai\Providers\Tools\FileSearchQuery): mixed|array<string, mixed>|null $where Filters
     */
    public function __construct(
        public array $stores,
        Closure|array|null $where = null,
    ) {
        $this->filters = $this->resolveFilters($where);
    }

    /**
     * Get the string store IDs assigned to the tool.
     *
     * @return array<string>
     */
    public function ids(): array
    {
        return collection($this->stores)
            ->map(fn($store) => $store instanceof Store
                ? $store->id
                : $store)->toList();
    }

    /**
     * Resolve the filters from the given value.
     *
     * @param (\Closure(\Crustum\Ai\Providers\Tools\FileSearchQuery): mixed)|array<string, mixed>|null $where Filters
     * @return array<int, array{type: string, key: string, value: mixed}>
     */
    protected function resolveFilters(Closure|array|null $where): array
    {
        if (is_null($where)) {
            return [];
        }

        if (is_array($where)) {
            return collection($where)->map(fn($value, $key): array => [
                'type' => 'eq',
                'key' => $key,
                'value' => $value,
            ])->toList();
        }

        $where($query = new FileSearchQuery());

        return $query->toArray();
    }
}
