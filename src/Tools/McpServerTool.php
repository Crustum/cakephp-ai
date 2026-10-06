<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use Crustum\Ai\Approvals\Approval;
use Crustum\Ai\Contracts\Approvable;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Trait\NormalizesMcpResultTrait;
use Crustum\Ai\Trait\InteractsWithApprovalsTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\Mcp\Request as McpRequest;
use Crustum\Mcp\Response as McpResponse;
use Crustum\Mcp\ResponseFactory as McpResponseFactory;
use Crustum\Mcp\Server\Content\ResourceLink;
use Crustum\Mcp\Server\Tool as McpServerToolContract;
use Crustum\Mcp\Support\ContainerRegistry;
use Crustum\Mcp\Support\McpContainerBindings;
use Generator;
use LogicException;

/**
 * Wraps an MCP server tool as a native tool.
 */
class McpServerTool implements Approvable, Tool
{
    use InteractsWithApprovalsTrait;
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
     * Get the MCP annotations describing the tool's behavior.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return $this->tool->annotations();
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
     * Reduce a list of responses to tool output, preserving MCP App resource links.
     *
     * When an MCP tool returns a `ResourceLink` with a `ui://` URI alongside
     * text, the URI is included as `appResourceUri` in a JSON payload so the
     * host can fetch the HTML via `resources/read` and render it in an iframe.
     * Without a `ResourceLink`, returns plain text as before.
     *
     * @param array<int, \Crustum\Mcp\Response> $responses Response objects
     * @return string
     */
    protected function finalResponse(array $responses): string
    {
        $text = '';
        $appUri = null;
        $isError = false;

        foreach (array_reverse($responses) as $response) {
            if ($response->isNotification()) {
                continue;
            }

            $content = $response->content();

            if ($content instanceof ResourceLink && str_starts_with((string)$content, 'ui://')) {
                $appUri ??= (string)$content;
                $isError = $isError || $response->isError();
                continue;
            }

            if ($text === '') {
                $text = (string)$content;
                $isError = $response->isError();
            }

            if ($text !== '' && $appUri !== null) {
                break;
            }
        }

        if ($text === '' && $appUri === null) {
            return '';
        }

        if ($isError) {
            $text = $this->errorMessage($text);
        }

        if ($appUri !== null) {
            return json_encode([
                'text' => $text,
                'appResourceUri' => $appUri,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return $text;
    }

    /**
     * Determine whether the tool needs approval for the given request.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request
     * @return \Crustum\Ai\Approvals\Approval|bool
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
