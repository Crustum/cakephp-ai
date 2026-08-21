<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Ollama\Trait;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Providers\Tools\ProviderTool;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Tools\ToolNameResolver;
use Crustum\Ai\Utility\Reflection;
use Crustum\Ai\Utility\Value;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use RuntimeException;

/**
 * Maps tools to Ollama function definitions.
 */
trait MapsToolsTrait
{
    /**
     * Map the given tools to Ollama function definitions.
     *
     * @param array<int, mixed> $tools Available tools
     * @return array<int, array<string, mixed>>
     */
    protected function mapTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                throw new RuntimeException(
                    'Ollama does not support [' . Reflection::classBasename($tool) . '] provider tools.',
                );
            }

            if ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a regular tool to an Ollama function definition.
     *
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @return array<string, mixed>
     */
    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory());

        $schemaArray = Value::filled($schema)
            ? (new ObjectSchema($schema))->toSchema()
            : [];

        return [
            'type' => 'function',
            'function' => [
                'name' => ToolNameResolver::resolve($tool),
                'description' => (string)$tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $schemaArray['properties'] ?? (object)[],
                    'required' => $schemaArray['required'] ?? [],
                ],
            ],
        ];
    }
}
