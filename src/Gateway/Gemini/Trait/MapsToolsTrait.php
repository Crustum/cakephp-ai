<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\SupportsCodeExecution;
use Crustum\Ai\Contracts\Providers\SupportsFileSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebFetch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Providers\Tools\ProviderTool;
use Crustum\Ai\Providers\Tools\WebFetch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Tools\ToolNameResolver;
use Crustum\Ai\Utility\Value;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use RuntimeException;

/**
 * Maps tools to Gemini tool definitions.
 */
trait MapsToolsTrait
{
    /**
     * Map the given tools to Gemini tool definitions.
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
     * Map a regular tool to a Gemini function declaration.
     *
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @return array<string, mixed>
     */
    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory());

        $definition = [
            'type' => 'function',
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string)$tool->description(),
        ];

        if (Value::filled($schema)) {
            $schemaArray = (new ObjectSchema($schema))->toSchema();

            $definition['parameters'] = $this->convertNullableTypes([
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? [],
                'required' => $schemaArray['required'] ?? [],
            ]);
        }

        return $definition;
    }

    /**
     * Recursively convert JSON Schema nullable types to OpenAPI-style for Gemini.
     *
     * @param array<string, mixed> $schema Schema node
     * @return array<string, mixed>
     */
    protected function convertNullableTypes(array $schema): array
    {
        unset($schema['additionalProperties']);

        if (is_array($schema['type'] ?? null) && in_array('null', $schema['type'], true)) {
            $remaining = array_values(array_diff($schema['type'], ['null']));

            if (count($remaining) === 1) {
                $schema['type'] = $remaining[0];
                $schema['nullable'] = true;
            }
        }

        if (isset($schema['properties'])) {
            $schema['properties'] = array_map(
                fn(mixed $property): mixed => $this->convertNullableTypes($property),
                $schema['properties'],
            );
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->convertNullableTypes($schema['items']);
        }

        return $schema;
    }

    /**
     * Map a provider tool to a Gemini provider tool definition.
     *
     * @param \Crustum\Ai\Providers\Tools\ProviderTool $tool Provider tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof CodeExecution => $this->mapCodeExecutionTool($tool, $provider),
            $tool instanceof FileSearch => $this->mapFileSearchTool($tool, $provider),
            $tool instanceof WebFetch => $this->mapWebFetchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new RuntimeException('Provider [' . $provider->name() . '] does not support the [' . class_basename($tool) . '] tool.'),
        };
    }

    /**
     * Map a code execution tool to a Gemini code execution definition.
     *
     * @param \Crustum\Ai\Providers\Tools\CodeExecution $tool Code execution tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapCodeExecutionTool(CodeExecution $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsCodeExecution) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support code execution.');
        }

        return array_merge(['type' => 'code_execution'], $provider->codeExecutionToolOptions($tool));
    }

    /**
     * Map a file search tool to a Gemini file search definition.
     *
     * @param \Crustum\Ai\Providers\Tools\FileSearch $tool File search tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapFileSearchTool(FileSearch $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsFileSearch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support file search.');
        }

        return array_merge(['type' => 'file_search'], $provider->fileSearchToolOptions($tool));
    }

    /**
     * Map a web fetch tool to a Gemini URL context definition.
     *
     * @param \Crustum\Ai\Providers\Tools\WebFetch $tool Web fetch tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapWebFetchTool(WebFetch $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsWebFetch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support web fetch.');
        }

        return array_merge(['type' => 'url_context'], $provider->webFetchToolOptions($tool));
    }

    /**
     * Map a web search tool to a Gemini Google Search definition.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $tool Web search tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support web search.');
        }

        return array_merge(['type' => 'google_search'], $provider->webSearchToolOptions($tool));
    }
}
