<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Providers\SupportsToolSearch;
use Crustum\Ai\Gateway\OpenAi\Trait\MapsToolsTrait;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Providers\Tools\WebFetch;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\NonStrictTool;

function openAiToolSearchMapper(): object
{
    return new class
    {
        use MapsToolsTrait;

        public function map(array $tools, Provider $provider, bool $stateless = false): array
        {
            return $this->mapTools($tools, $provider, $stateless);
        }
    };
}

function openAiToolSearchProvider(): Provider
{
    return new class extends Provider implements SupportsToolSearch
    {
        public function __construct()
        {
        }

        public function name(): string
        {
            return 'openai';
        }
    };
}

test('emits the tool_search entry and defers the tools nested in the ToolSearch tool', function (): void {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool(), new ToolSearch(tools: [new FixedNumberGenerator()])],
        openAiToolSearchProvider(),
    );

    $search = collect($mapped)->filter(fn($t): bool => ($t['type'] ?? null) === 'tool_search')->first();
    $deferred = collect($mapped)->filter(fn($t): bool => isset($t['defer_loading']) && $t['defer_loading'])->first();
    $nonDeferred = collect($mapped)->filter(
        fn($t): bool => ($t['type'] ?? null) === 'function' && !isset($t['defer_loading']),
    );

    expect($search)->toBe(['type' => 'tool_search'])
        ->and($deferred)->not->toBeNull()
        ->and($nonDeferred)->toHaveCount(1);
});

test('throws for a provider tool OpenAI cannot map instead of emitting an empty entry', function (): void {
    expect(fn() => openAiToolSearchMapper()->map([new WebFetch()], openAiToolSearchProvider()))
        ->toThrow(RuntimeException::class, 'does not support the [WebFetch] tool');
});

test('throws when response storage is disabled because hosted search requires stored responses', function (): void {
    expect(fn() => openAiToolSearchMapper()->map(
        [new NonStrictTool(), new ToolSearch(tools: [new FixedNumberGenerator()])],
        openAiToolSearchProvider(),
        stateless: true,
    ))->toThrow(LogicException::class, 'store=false');
});

test('forwards provider options onto the tool_search entry', function (): void {
    $search = (new ToolSearch(tools: [new FixedNumberGenerator()]))
        ->withProviderOptions(['foo' => 'bar']);

    $mapped = openAiToolSearchMapper()->map([new NonStrictTool(), $search], openAiToolSearchProvider());

    expect(collect($mapped)->filter(fn($t): bool => ($t['type'] ?? null) === 'tool_search')->first())
        ->toBe(['type' => 'tool_search', 'foo' => 'bar']);
});

test('skips an empty ToolSearch tool without emitting a tool_search entry', function (): void {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool(), new ToolSearch()],
        openAiToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(1)
        ->and(collect($mapped)->extract('type')->toList())->not->toContain('tool_search');
});

test('does not emit a tool_search entry when no ToolSearch tool is present', function (): void {
    $mapped = openAiToolSearchMapper()->map(
        [new NonStrictTool(), new FixedNumberGenerator()],
        openAiToolSearchProvider(),
    );

    expect($mapped)->toHaveCount(2)
        ->and(collect($mapped)->extract('type')->toList())->not->toContain('tool_search')
        ->and(collect($mapped)->contains(fn($t): bool => isset($t['defer_loading'])))->toBeFalse();
});
