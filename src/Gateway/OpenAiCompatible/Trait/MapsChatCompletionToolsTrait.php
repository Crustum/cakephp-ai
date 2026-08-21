<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAiCompatible\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Providers\Tools\ProviderTool;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Tools\ToolNameResolver;
use Crustum\Ai\Utility\Reflection;
use Crustum\Ai\Utility\Value;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use RuntimeException;

/**
 * Maps tools to OpenAI Chat Completions function definitions.
 */
trait MapsChatCompletionToolsTrait
{
    /**
     * Map the given tools to Chat Completions function definitions.
     *
     * @param array<int, mixed> $tools Available tools
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<int, array<string, mixed>>
     */
    protected function mapTools(array $tools, Provider $provider): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                $mapped[] = $this->mapProviderTool($tool, $provider);
            } elseif ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a provider tool to a Chat Completions tool definition.
     *
     * @param \Crustum\Ai\Providers\Tools\ProviderTool $tool Provider tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        $gatewayName = preg_replace('/Gateway$/', '', Reflection::classBasename(static::class));

        throw new RuntimeException($gatewayName . ' does not support [' . Reflection::classBasename($tool) . '] provider tools.');
    }

    /**
     * Map a regular tool to a Chat Completions function definition.
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
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Map a tool choice to the Chat Completions tool_choice shape.
     *
     * @param \Crustum\Ai\Support\ToolChoice $choice Tool choice
     * @return array<string, mixed>|string
     */
    protected function mapToolChoice(ToolChoice $choice): string|array
    {
        return match ($choice->mode) {
            ToolChoice::AUTO, ToolChoice::NONE, ToolChoice::REQUIRED => $choice->mode,
            ToolChoice::TOOL => [
                'type' => 'function',
                'function' => [
                    'name' => $choice->toolName,
                ],
            ],
        };
    }
}
