<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Mcp;

use Crustum\Mcp\Client\Primitives\Tool;
use Crustum\Mcp\Client\Schema\ToolResult;
use Override;

class FakeMcpTool extends Tool
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>|null  $outputSchema
     * @param  array<string, mixed>  $annotations
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        protected FakeMcpClient $fakeClient,
        string $name,
        ?string $title,
        ?string $description,
        array $inputSchema,
        ?array $outputSchema = null,
        array $annotations = [],
        ?array $meta = null,
    ) {
        parent::__construct(null, $name, $title, $description, $inputSchema, $outputSchema, $annotations, $meta);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[Override]
    public function call(array $arguments = []): ToolResult
    {
        return $this->fakeClient->callTool($this->name, $arguments);
    }
}
