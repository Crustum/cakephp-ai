<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Providers\SupportsToolSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Gateway\Anthropic\Trait\MapsToolsTrait;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Test\Fixtures\Tools\DeferredTool;
use Crustum\Ai\Test\Fixtures\Tools\NonStrictTool;

function anthropicToolSearchMapper(): object
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

function anthropicToolSearchProvider(): Provider
{
    return new class extends Provider implements SupportsToolSearch
    {
        public function __construct()
        {
        }

        public function name(): string
        {
            return 'anthropic';
        }
    };
}

test('emits the regex tool search entry and defers the tools nested in the ToolSearch tool', function (): void {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool(), new ToolSearch(tools: [new DeferredTool()])],
        anthropicToolSearchProvider(),
    );

    $search = collect($mapped)->filter(fn($t): bool => ($t['type'] ?? null) === 'tool_search_tool_regex_20251119')->first();

    expect($search)->toBe([
        'type' => 'tool_search_tool_regex_20251119',
        'name' => 'tool_search_tool_regex',
    ]);
    expect($search)->not->toHaveKey('defer_loading');

    $deferred = collect($mapped)->filter(fn($t): bool => ($t['defer_loading'] ?? false) === true)->first();
    $nonDeferred = collect($mapped)->filter(
        fn($t): bool => isset($t['input_schema']) && !isset($t['defer_loading']),
    );

    expect($deferred)->not->toBeNull()
        ->and($deferred['description'])->toContain('deferred')
        ->and($nonDeferred)->toHaveCount(1);
});

test('emits the bm25 tool search entry when that strategy is passed as a parameter', function (): void {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool(), new ToolSearch(tools: [new DeferredTool()], strategy: 'bm25')],
        anthropicToolSearchProvider(),
    );

    expect(collect($mapped)->filter(fn($t): bool => ($t['type'] ?? null) === 'tool_search_tool_bm25_20251119')->first())->toBe([
        'type' => 'tool_search_tool_bm25_20251119',
        'name' => 'tool_search_tool_bm25',
    ]);
});

test('does not leak the strategy parameter onto the tool search entry', function (): void {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool(), new ToolSearch(tools: [new DeferredTool()], strategy: 'regex')],
        anthropicToolSearchProvider(),
    );

    expect(collect($mapped)->filter(fn($t): bool => ($t['type'] ?? null) === 'tool_search_tool_regex_20251119')->first())
        ->not->toHaveKey('strategy');
});

test('forwards provider options onto the tool search entry', function (): void {
    $search = (new ToolSearch(tools: [new DeferredTool()]))
        ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

    $mapped = anthropicToolSearchMapper()->map([new NonStrictTool(), $search], anthropicToolSearchProvider());

    expect(collect($mapped)->filter(fn($t): bool => ($t['type'] ?? null) === 'tool_search_tool_regex_20251119')->first())
        ->toBe([
            'type' => 'tool_search_tool_regex_20251119',
            'name' => 'tool_search_tool_regex',
            'cache_control' => ['type' => 'ephemeral'],
        ]);
});

test('does not emit a tool_search entry when no ToolSearch tool is present', function (): void {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool()],
        anthropicToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(1)
        ->and(collect($mapped)->some(fn($t): bool => isset($t['defer_loading'])))->toBeFalse();
});

test('maps a ToolSearch whose only tools are deferred, since the search entry itself is the non-deferred tool', function (): void {
    $mapped = anthropicToolSearchMapper()->map(
        [new ToolSearch(tools: [new DeferredTool(), new DeferredTool()])],
        anthropicToolSearchProvider(),
    );

    expect(collect($mapped)->filter(fn($t): bool => ($t['type'] ?? null) === 'tool_search_tool_regex_20251119')->first())->not->toBeNull()
        ->and(collect($mapped)->filter(fn($t): bool => ($t['defer_loading'] ?? false) === true))->toHaveCount(2);
});

test('maps a ToolSearch alongside a server tool', function (): void {
    $provider = new class extends Provider implements SupportsToolSearch, SupportsWebSearch
    {
        public function __construct()
        {
        }

        public function name(): string
        {
            return 'anthropic';
        }

        public function webSearchToolOptions(WebSearch $search): array
        {
            return [];
        }
    };

    $mapped = anthropicToolSearchMapper()->map(
        [new WebSearch(), new ToolSearch(tools: [new DeferredTool()])],
        $provider,
    );

    expect(collect($mapped)->filter(fn($t): bool => ($t['type'] ?? null) === 'tool_search_tool_regex_20251119')->first())->not->toBeNull()
        ->and(collect($mapped)->filter(fn($t): bool => ($t['type'] ?? null) === 'web_search_20250305')->first())->not->toBeNull();
});

test('skips an empty ToolSearch tool without emitting a search entry', function (): void {
    $mapped = anthropicToolSearchMapper()->map(
        [new NonStrictTool(), new ToolSearch()],
        anthropicToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(1)
        ->and(collect($mapped)->some(fn($t): bool => str_starts_with($t['type'] ?? '', 'tool_search')))->toBeFalse();
});
