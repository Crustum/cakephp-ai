<?php
declare(strict_types=1);

use Crustum\Ai\Gateway\Trait\InvokesToolsTrait;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Test\Fixtures\Tools\DeferredTool;
use Crustum\Ai\Test\Fixtures\Tools\NonStrictTool;

function toolFinder(): object
{
    return new class
    {
        use InvokesToolsTrait;

        public function find(string $name, array $tools): mixed
        {
            return $this->findTool($name, $tools);
        }
    };
}

test('finds a top-level tool by name', function (): void {
    $tool = new NonStrictTool();

    expect(toolFinder()->find('NonStrictTool', [$tool]))->toBe($tool);
});

test('finds a tool nested inside a ToolSearch tool', function (): void {
    $nested = new DeferredTool();

    $found = toolFinder()->find('DeferredTool', [
        new NonStrictTool(),
        new ToolSearch(tools: [$nested]),
    ]);

    expect($found)->toBe($nested);
});

test('returns null when the tool is not present anywhere', function (): void {
    $found = toolFinder()->find('Missing', [
        new NonStrictTool(),
        new ToolSearch(tools: [new DeferredTool()]),
    ]);

    expect($found)->toBeNull();
});
