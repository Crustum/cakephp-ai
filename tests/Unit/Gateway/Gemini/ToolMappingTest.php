<?php
declare(strict_types=1);

use Crustum\Ai\Gateway\Gemini\Trait\MapsToolsTrait;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Test\Fixtures\Tools\NonStrictTool;

function geminiToolMapper(): object
{
    return new class
    {
        use MapsToolsTrait;

        public function map(array $tools, Provider $provider): array
        {
            return $this->mapTools($tools, $provider);
        }
    };
}

function geminiToolMappingProvider(): Provider
{
    return new class extends Provider
    {
        public function __construct()
        {
        }

        public function name(): string
        {
            return 'gemini';
        }
    };
}

test('throws for a provider tool Gemini cannot map instead of emitting an empty entry', function (): void {
    expect(fn() => geminiToolMapper()->map(
        [new ToolSearch(tools: [new NonStrictTool()])],
        geminiToolMappingProvider(),
    ))->toThrow(RuntimeException::class, 'does not support the [ToolSearch] tool');
});
