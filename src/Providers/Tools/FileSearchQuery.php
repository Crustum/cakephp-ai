<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Tools;

use Cake\Collection\Collection;

/**
 * File search query builder.
 *
 * Provides fluent interface for building file search filters.
 */
class FileSearchQuery
{
    /**
     * The defined filters.
     *
     * @var array<int, array{type: string, key: string, value: mixed}>
     */
    protected array $filters = [];

    /**
     * Add a "where" filter to the file search.
     *
     * @param string $key Metadata key
     * @param mixed $value Expected value
     */
    public function where(string $key, mixed $value): static
    {
        $this->filters[] = [
            'type' => 'eq',
            'key' => $key,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a "where not" filter to the file search.
     *
     * @param string $key Metadata key
     * @param mixed $value Excluded value
     */
    public function whereNot(string $key, mixed $value): static
    {
        $this->filters[] = [
            'type' => 'ne',
            'key' => $key,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a "where in" filter to the file search.
     *
     * @param string $key Metadata key
     * @param \Cake\Collection\Collection<int, mixed>|array<int, mixed> $values Allowed values
     */
    public function whereIn(string $key, Collection|array $values): static
    {
        $collection = is_array($values) ? collection($values) : $values;

        $this->filters[] = [
            'type' => 'in',
            'key' => $key,
            'value' => $collection->toList(),
        ];

        return $this;
    }

    /**
     * Add a "where not in" filter to the file search.
     *
     * @param string $key Metadata key
     * @param \Cake\Collection\Collection<int, mixed>|array<int, mixed> $values Excluded values
     */
    public function whereNotIn(string $key, Collection|array $values): static
    {
        $collection = is_array($values) ? collection($values) : $values;

        $this->filters[] = [
            'type' => 'nin',
            'key' => $key,
            'value' => $collection->toList(),
        ];

        return $this;
    }

    /**
     * Get the filters as an array.
     *
     * @return array<int, array{type: string, key: string, value: mixed}>
     */
    public function toArray(): array
    {
        return $this->filters;
    }
}
