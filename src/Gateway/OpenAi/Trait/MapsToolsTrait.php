<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi\Trait;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\SupportsCodeExecution;
use Crustum\Ai\Contracts\Providers\SupportsFileSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Providers\Tools\ProviderTool;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Tools\ToolNameResolver;
use Crustum\Ai\Utility\Value;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use LogicException;
use RuntimeException;

/**
 * Maps tools to OpenAI Responses API tool definitions.
 */
trait MapsToolsTrait
{
    /**
     * Map the given tools to OpenAI tool definitions.
     *
     * @param array<int, mixed> $tools Available tools
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param bool $stateless Whether response storage is disabled
     * @return array<int, array<string, mixed>>
     */
    protected function mapTools(array $tools, Provider $provider, bool $stateless = false): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ToolSearch) {
                $this->guardStatelessToolSearch($provider, $stateless);

                if (blank($tool->tools)) {
                    continue;
                }

                $mapped[] = [
                    'type' => 'tool_search',
                    ...array_diff_key($tool->providerOptions(Lab::tryFrom($provider->driver()) ?? $provider->driver()), ['type' => true]),
                ];

                foreach ($tool->tools as $deferred) {
                    $mapped[] = $this->mapTool($deferred, defer: true);
                }
            } elseif ($tool instanceof ProviderTool) {
                $mapped[] = $this->mapProviderTool($tool, $provider);
            } elseif ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Ensure hosted tool search is not used while response storage is disabled.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param bool $stateless Whether response storage is disabled
     */
    protected function guardStatelessToolSearch(Provider $provider, bool $stateless): void
    {
        if ($stateless) {
            throw new LogicException(
                "Provider [{$provider->name()}] does not support tool search when response storage is disabled (store=false).",
            );
        }
    }

    /**
     * Map a regular tool to an OpenAI function definition.
     *
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @param bool $defer Whether the tool should be deferred for hosted tool search
     * @return array<string, mixed>
     */
    protected function mapTool(Tool $tool, bool $defer = false): array
    {
        $strict = Strict::isAppliedTo($tool);
        $schema = $tool->schema(new JsonSchemaTypeFactory());
        $schemaArray = Value::filled($schema)
            ? (new ObjectSchema($schema, strict: $strict))->toSchema()
            : [];

        $definition = [
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

        if ($defer) {
            $definition['defer_loading'] = true;
        }

        return $definition;
    }

    /**
     * Map a provider tool to an OpenAI provider tool definition.
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
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new RuntimeException('Provider [' . $provider->name() . '] does not support the [' . basename(str_replace('\\', '/', $tool::class)) . '] tool.'),
        };
    }

    /**
     * Map a code execution tool to an OpenAI code interpreter definition.
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

        return [
            'type' => 'code_interpreter',
            ...$provider->codeExecutionToolOptions($tool),
        ];
    }

    /**
     * Map a file search tool to an OpenAI file search definition.
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

        return [
            'type' => 'file_search',
            ...$provider->fileSearchToolOptions($tool),
        ];
    }

    /**
     * Map a web search tool to an OpenAI web search definition.
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

        return [
            'type' => 'web_search',
            ...$provider->webSearchToolOptions($tool),
        ];
    }
}
