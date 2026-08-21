<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;

class SideEffectRecorder implements Tool
{
    public static int $invocations = 0;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Records an irreversible side effect.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        static::$invocations++;

        return 'recorded';
    }

    /**
     * Get the tool's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema Schema builder.
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
