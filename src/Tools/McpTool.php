<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Schema\SchemaNormalizer;
use Crustum\Ai\Tools\Trait\NormalizesMcpResultTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\JsonSchema\JsonSchema as JsonSchemaFactory;
use Crustum\JsonSchema\Types\ObjectType;
use Crustum\Mcp\Client\Primitives\Tool as McpClientTool;
use Crustum\Mcp\Client\Schema\ToolResult;
use Throwable;

/**
 * Wraps an MCP client tool primitive as a native tool.
 */
class McpTool implements Tool
{
    use NormalizesMcpResultTrait;

    protected const NAME_PREFIX = 'mcp_tools_';

    /**
     * @param \Crustum\Mcp\Client\Primitives\Tool $tool MCP client tool primitive
     */
    public function __construct(protected McpClientTool $tool)
    {
    }

    /**
     * Determine whether the given value is an MCP client tool primitive.
     *
     * @param mixed $tool Tool candidate
     * @return bool
     */
    public static function supports(mixed $tool): bool
    {
        return $tool instanceof McpClientTool;
    }

    /**
     * Get the name of the tool.
     *
     * @return string
     */
    public function name(): string
    {
        return self::NAME_PREFIX . $this->tool->name;
    }

    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): string
    {
        return $this->tool->description ?? $this->tool->title ?? $this->tool->name;
    }

    /**
     * Execute the tool.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request
     * @return string
     */
    public function handle(Request $request): string
    {
        return $this->convertResult($this->tool->call($request->all()));
    }

    /**
     * Get the tool's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema Schema builder
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $input = $this->tool->inputSchema;

        if ($input === []) {
            return [];
        }

        try {
            $type = JsonSchemaFactory::fromArray(SchemaNormalizer::normalize($input));
        } catch (Throwable) {
            return [];
        }

        return $type instanceof ObjectType
            ? (fn(): array => $this->properties)->call($type)
            : [];
    }

    /**
     * Convert an MCP tool result into tool output.
     *
     * @param \Crustum\Mcp\Client\Schema\ToolResult $result MCP tool result
     * @return string
     */
    protected function convertResult(ToolResult $result): string
    {
        if ($result->isError) {
            return $this->errorResult($result);
        }

        if ($result->structuredContent !== null) {
            return $this->json($result->structuredContent);
        }

        return $result->text();
    }

    /**
     * Convert an MCP error result into tool output.
     *
     * @param \Crustum\Mcp\Client\Schema\ToolResult $result MCP tool result
     * @return string
     */
    protected function errorResult(ToolResult $result): string
    {
        $text = $result->text();

        if ($text === '' && $result->structuredContent !== null) {
            $text = $this->json($result->structuredContent);
        }

        return $this->errorMessage($text);
    }
}
