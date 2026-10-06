<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Crustum\Ai\Support\AiCollection;

/**
 * Collections facade for AI-backed collection helpers.
 */
class Collections
{
    /**
     * Wrap items for fluent AI-backed collection helpers.
     *
     * @template TKey
     * @template TValue
     * @param iterable<TKey, TValue> $items Items to wrap
     * @return \Crustum\Ai\Support\AiCollection<TKey, TValue>
     */
    public static function of(iterable $items): AiCollection
    {
        return new AiCollection($items);
    }
}
