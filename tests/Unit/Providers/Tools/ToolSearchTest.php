<?php
declare(strict_types=1);

use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Test\Fixtures\Tools\TextGenerationLoopCountingTool;

test('accepts a deferred tool set', function (): void {
    $tool = new TextGenerationLoopCountingTool();

    expect((new ToolSearch(tools: [$tool]))->tools)->toBe([$tool]);
});

test('withTools returns a new instance without mutating the original', function (): void {
    $tool = new TextGenerationLoopCountingTool();
    $original = new ToolSearch();

    $updated = $original->withTools([$tool]);

    expect($updated)->not->toBe($original)
        ->and($updated->tools)->toBe([$tool])
        ->and($original->tools)->toBe([]);
});

test('withTools preserves the strategy and provider options', function (): void {
    $original = (new ToolSearch(strategy: 'bm25'))
        ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

    $updated = $original->withTools([new TextGenerationLoopCountingTool()]);

    expect($updated->strategy)->toBe('bm25')
        ->and($updated->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']]);
});

test('carries provider options for a specific provider', function (): void {
    $search = (new ToolSearch())->withProviderOptions(
        fn(Lab $provider): ?array => $provider === Lab::Anthropic ? ['cache_control' => ['type' => 'ephemeral']] : null,
    );

    expect($search->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']])
        ->and($search->providerOptions(Lab::OpenAI))->toBe([]);
});

test('accepts a search strategy', function (): void {
    expect((new ToolSearch(strategy: 'bm25'))->strategy)->toBe('bm25')
        ->and((new ToolSearch())->strategy)->toBeNull();
});

test('rejects an unknown search strategy', function (): void {
    new ToolSearch(strategy: 'semantic');
})->throws(InvalidArgumentException::class, 'Invalid tool search strategy [semantic]');
