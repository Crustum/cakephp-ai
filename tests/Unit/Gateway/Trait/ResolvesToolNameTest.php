<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Gateway\Trait\InvokesToolsTrait;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\NamedTool;
use Crustum\Ai\Test\Fixtures\Tools\ProtectedNameTool;
use Crustum\Ai\Tools\ToolNameResolver;

function resolverHost(): object
{
    return new class
    {
        use InvokesToolsTrait;

        public function callResolve(Tool $tool): string
        {
            return ToolNameResolver::resolve($tool);
        }

        public function callFind(string $name, array $tools): ?Tool
        {
            return $this->findTool($name, $tools);
        }
    };
}

test('resolveToolName falls back to class basename when tool has no name() method', function (): void {
    $host = resolverHost();

    expect($host->callResolve(new FixedNumberGenerator()))->toBe('FixedNumberGenerator');
});

test('resolveToolName prefers the declared name() method when present', function (): void {
    $host = resolverHost();

    expect($host->callResolve(new NamedTool('aliased_tool')))
        ->toBe('aliased_tool');
});

test('resolveToolName falls back when name() is not callable', function (): void {
    expect(ToolNameResolver::resolve(new ProtectedNameTool()))->toBe('ProtectedNameTool');
});

test('findTool matches a tool by its declared name() when multiple share a class', function (): void {
    $host = resolverHost();

    $tools = [
        new NamedTool('aliased_tool'),
        new NamedTool('other_aliased_tool'),
        new FixedNumberGenerator(),
    ];

    expect($host->callFind('other_aliased_tool', $tools))->toBe($tools[1])
        ->and($host->callFind('FixedNumberGenerator', $tools))->toBe($tools[2])
        ->and($host->callFind('unknown', $tools))->toBeNull();
});

test('findTool returns null when no tool matches', function (): void {
    $host = resolverHost();

    expect($host->callFind('missing', [new FixedNumberGenerator()]))->toBeNull();
});
