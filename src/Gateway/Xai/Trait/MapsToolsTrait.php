<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Xai\Trait;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\SupportsCodeExecution;
use Crustum\Ai\Contracts\Providers\SupportsFileSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Providers\Tools\ProviderTool;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Tools\ToolNameResolver;
use Crustum\Ai\Utility\Value;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use RuntimeException;

/**
 * Maps tools to xAI function definitions.
 */
trait MapsToolsTrait
{
    /**
     * Map the given tools to xAI function definitions.
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
     * Map a provider tool to an xAI provider tool definition.
     *
     * @param \Crustum\Ai\Providers\Tools\ProviderTool $tool Provider tool instance
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof CodeExecution => $this->mapCodeExecutionTool($tool, $provider),
            $tool instanceof FileSearch => $this->mapFileSearchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new RuntimeException('Provider [' . $provider->name() . '] does not support the [' . class_basename($tool) . '] tool.'),
        };
    }

    /**
     * Map a code execution tool to an xAI code interpreter definition.
     *
     * @param \Crustum\Ai\Providers\Tools\CodeExecution $tool Code execution tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     * @throws \RuntimeException When the provider does not support code execution
     */
    protected function mapCodeExecutionTool(CodeExecution $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsCodeExecution) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support code execution.');
        }

        return [
            'type' => 'code_interpreter',
            ...$provider->codeExecutionToolOptions($tool),
        ];
    }

    /**
     * Map a file search tool to an xAI file search definition.
     *
     * @param \Crustum\Ai\Providers\Tools\FileSearch $tool File search tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     * @throws \RuntimeException When the provider does not support file search
     */
    protected function mapFileSearchTool(FileSearch $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsFileSearch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support file search.');
        }

        return [
            'type' => 'file_search',
            ...$provider->fileSearchToolOptions($tool),
        ];
    }

    /**
     * Map a web search tool to an xAI web search definition.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $tool Web search tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     * @throws \RuntimeException When the provider does not support web search
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support web search.');
        }

        return [
            'type' => 'web_search',
            ...$provider->webSearchToolOptions($tool),
        ];
    }

    /**
     * Map a regular tool to an xAI function definition.
     *
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @return array<string, mixed>
     */
    protected function mapTool(Tool $tool): array
    {
        $strict = Strict::isAppliedTo($tool);

        $schema = $tool->schema(new JsonSchemaTypeFactory());

        $schemaArray = Value::filled($schema)
            ? (new ObjectSchema($schema, strict: $strict))->toSchema()
            : [];

        return [
            'type' => 'function',
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string)$tool->description(),
            'strict' => $strict,
            'parameters' => [
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? (object)[],
                'required' => $schemaArray['required'] ?? [],
                'additionalProperties' => false,
            ],
        ];
    }
}
