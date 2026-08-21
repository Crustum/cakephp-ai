<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;

class NullableParamTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'A tool with nullable parameters for testing.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'ok';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'email' => $schema->string()->nullable()->required(),
            'age' => $schema->integer()->nullable(),
        ];
    }
}
