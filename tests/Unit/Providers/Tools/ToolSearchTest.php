<?php
declare(strict_types=1);

use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Test\Fixtures\Tools\TextGenerationLoopCountingTool;

test('withTools returns a new instance preserving the strategy and provider options', function (): void {
    $tool = new TextGenerationLoopCountingTool();
    $original = (new ToolSearch(strategy: 'bm25'))
        ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

    $updated = $original->withTools([$tool]);

    expect($updated)->not->toBe($original)
        ->and($updated->tools)->toBe([$tool])
        ->and($original->tools)->toBe([])
        ->and($updated->strategy)->toBe('bm25')
        ->and($updated->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']]);
});

test('carries provider options for a specific provider', function (): void {
    $search = (new ToolSearch())->withProviderOptions(
        fn(Lab $provider): ?array => $provider === Lab::Anthropic ? ['cache_control' => ['type' => 'ephemeral']] : null,
    );

    expect($search->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']])
        ->and($search->providerOptions(Lab::OpenAI))->toBe([]);
});

test('rejects an unknown search strategy', function (): void {
    new ToolSearch(strategy: 'semantic');
})->throws(InvalidArgumentException::class, 'Invalid tool search strategy [semantic]');
