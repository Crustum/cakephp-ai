<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Trait\NormalizesMcpResultTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\Mcp\Request as McpRequest;
use Crustum\Mcp\Response as McpResponse;
use Crustum\Mcp\ResponseFactory as McpResponseFactory;
use Crustum\Mcp\Server\Tool as McpServerToolContract;
use Crustum\Mcp\Support\ContainerRegistry;
use Crustum\Mcp\Support\McpContainerBindings;
use Generator;
use LogicException;

/**
 * Wraps an MCP server tool as a native tool.
 */
class McpServerTool implements Tool
{
    use NormalizesMcpResultTrait;

    /**
     * @param \Crustum\Mcp\Server\Tool $tool MCP server tool
     */
    public function __construct(protected McpServerToolContract $tool)
    {
    }

    /**
     * Determine whether the given value is an MCP server tool.
     *
     * @param mixed $tool Tool candidate
     * @return bool
     */
    public static function supports(mixed $tool): bool
    {
        return $tool instanceof McpServerToolContract;
    }

    /**
     * Get the name of the tool.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->tool->name();
    }

    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): string
    {
        return $this->tool->description();
    }

    /**
     * Execute the tool.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request
     * @return string
     */
    public function handle(Request $request): string
    {
        if (!method_exists($this->tool, 'handle')) {
            throw new LogicException('MCP server tool [' . $this->tool::class . '] does not implement handle().');
        }

        $container = ContainerRegistry::getInstance();
        $mcpRequest = new McpRequest($request->toArray());
        McpContainerBindings::bindRequest($container, $mcpRequest);

        try {
            $response = $this->tool->handle($mcpRequest);

            return $this->convertResponse($response);
        } finally {
            McpContainerBindings::releaseRequest($container);
        }
    }

    /**
     * Get the tool's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema Schema builder
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        /** @var array<string, \Crustum\JsonSchema\Types\Type> $properties */
        $properties = $this->tool->schema($schema);

        return $properties;
    }

    /**
     * Convert an MCP server response into tool output.
     *
     * @param mixed $response MCP response
     * @return string
     */
    protected function convertResponse(mixed $response): string
    {
        if ($response instanceof McpResponseFactory) {
            $structured = $response->getStructuredContent();

            if (is_array($structured) && $structured !== []) {
                return $this->json($structured);
            }

            /** @var array<int, \Crustum\Mcp\Response> $responses */
            $responses = $response->responses()->toList();

            return $this->finalResponse($responses);
        }

        $items = $response instanceof Generator
            ? iterator_to_array($response, false)
            : [$response];

        return $this->finalResponse($this->normalize($items));
    }

    /**
     * Flatten response items into a flat list of response instances.
     *
     * @param array<int, mixed> $items Response items
     * @return array<int, \Crustum\Mcp\Response>
     */
    protected function normalize(array $items): array
    {
        $responses = [];

        foreach ($items as $item) {
            if ($item instanceof McpResponse) {
                $responses[] = $item;
                continue;
            }

            if ($item instanceof McpResponseFactory) {
                /** @var array<int, \Crustum\Mcp\Response> $factoryResponses */
                $factoryResponses = $item->responses()->toList();
                foreach ($factoryResponses as $factoryResponse) {
                    $responses[] = $factoryResponse;
                }

                continue;
            }

            if (is_string($item)) {
                $responses[] = McpResponse::text($item);
                continue;
            }

            if (is_array($item)) {
                foreach ($this->normalize($item) as $nested) {
                    $responses[] = $nested;
                }
            }
        }

        return $responses;
    }

    /**
     * Reduce a list of responses to the last non-notification response's text.
     *
     * @param array<int, \Crustum\Mcp\Response> $responses Response objects
     * @return string
     */
    protected function finalResponse(array $responses): string
    {
        $final = array_find(array_reverse($responses), fn($response): bool => !$response->isNotification());
        if ($final === null) {
            return '';
        }

        $text = (string)$final->content();

        return $final->isError()
            ? $this->errorMessage($text)
            : $text;
    }
}
