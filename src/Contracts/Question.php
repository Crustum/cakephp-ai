<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * Question Interface
 *
 * Defines the contract for classification questions that can be
 * represented as a provider-neutral array.
 */
interface Question
{
    /**
     * Get the question as a provider-neutral array.
     *
     * @return array<string, mixed> The question definition
     */
    public function toArray(): array;
}
