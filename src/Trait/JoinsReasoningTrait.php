<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

/**
 * Joins Reasoning Trait
 *
 * Joins reasoning blocks, separating each block with a blank line.
 */
trait JoinsReasoningTrait
{
    /**
     * Join reasoning blocks, separating each block with a blank line.
     *
     * @param iterable<int, string> $blocks Reasoning blocks
     * @return string
     */
    protected static function joinReasoning(iterable $blocks): string
    {
        /** @var \Cake\Collection\CollectionInterface<int, string> $texts */
        $texts = collection($blocks)
            ->filter(fn(string $block): bool => trim($block) !== '');

        return implode("\n\n", $texts->toList());
    }
}
