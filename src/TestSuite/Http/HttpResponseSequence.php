<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Http;

/**
 * Sequence of HTTP response definitions.
 */
class HttpResponseSequence
{
    /**
     * @var array<int, \Crustum\Ai\TestSuite\Http\HttpResponseDefinition>
     */
    protected array $responses;

    protected int $index = 0;

    /**
     * @param array<int, \Crustum\Ai\TestSuite\Http\HttpResponseDefinition> $responses Response definitions
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /**
     * Get all response definitions in the sequence.
     *
     * @return array<int, \Crustum\Ai\TestSuite\Http\HttpResponseDefinition>
     */
    public function definitions(): array
    {
        return $this->responses;
    }

    /**
     * Get the next response definition.
     *
     * @return \Crustum\Ai\TestSuite\Http\HttpResponseDefinition
     */
    public function next(): HttpResponseDefinition
    {
        $response = $this->responses[$this->index] ?? end($this->responses);
        $this->index++;

        return $response instanceof HttpResponseDefinition
            ? $response
            : new HttpResponseDefinition([]);
    }
}
