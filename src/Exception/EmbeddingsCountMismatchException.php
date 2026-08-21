<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

/**
 * Exception thrown when a provider returns an embeddings count that does not
 * match the number of inputs requested during individual caching.
 */
class EmbeddingsCountMismatchException extends AiException
{
    /**
     * Constructor.
     *
     * @param int $expected The number of inputs sent to the provider
     * @param int $actual The number of embeddings returned by the provider
     */
    public function __construct(public readonly int $expected, public readonly int $actual)
    {
        parent::__construct(sprintf('Provider returned %d embeddings for %d inputs.', $actual, $expected));
    }
}
