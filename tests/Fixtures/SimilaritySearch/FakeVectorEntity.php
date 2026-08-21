<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\SimilaritySearch;

/**
 * Fake entity for similarity search tests.
 */
class FakeVectorEntity
{
    /**
     * @param array<string, mixed> $attributes Entity attributes.
     */
    public function __construct(protected array $attributes)
    {
    }

    /**
     * Get the entity as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
