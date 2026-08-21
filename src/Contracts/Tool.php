<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Stringable;

/**
 * Tool Interface
 *
 * Defines the contract for AI agent tools that can be invoked with structured inputs
 * and return outputs. Tools extend agent capabilities by providing specific functionality.
 */
interface Tool
{
    /**
     * Get the description of the tool's purpose.
     *
     * Returns a human-readable description that explains what the tool does and when it should be used.
     * This description helps the AI agent decide when to invoke this tool.
     *
     * @return \Stringable|string The tool's description.
     */
    public function description(): Stringable|string;

    /**
     * Execute the tool.
     *
     * Processes the tool request and returns the result. The request contains the structured
     * parameters provided by the AI agent based on the tool's schema.
     *
     * @param \Crustum\Ai\Tools\Request $request The tool invocation request with parameters.
     * @return \Stringable|string The tool's output or result.
     */
    public function handle(Request $request): Stringable|string;

    /**
     * Get the tool's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema The schema builder instance
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array;
}
