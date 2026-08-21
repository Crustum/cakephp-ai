<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use ArrayAccess;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\Trait\ProvidesStructuredResponseTrait;
use Override;

/**
 * Structured text response.
 *
 * Text response with structured output that conforms to a schema.
 * Implements ArrayAccess for convenient data access.
 */
class StructuredTextResponse extends TextResponse implements ArrayAccess
{
    use ProvidesStructuredResponseTrait;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $structured Structured output data
     * @param string $text Text representation
     * @param \Crustum\Ai\Responses\Data\Usage $usage Token usage
     * @param \Crustum\Ai\Responses\Data\Meta $meta Response metadata
     */
    public function __construct(array $structured, string $text, public Usage $usage, public Meta $meta)
    {
        parent::__construct($text, $usage, $meta);

        $this->structured = $structured;
        $this->toolCalls = collection([]);
        $this->toolResults = collection([]);
    }

    /**
     * Get the string representation of the object.
     *
     * @return string
     */
    #[Override]
    public function __toString(): string
    {
        return (string)json_encode($this->structured);
    }
}
