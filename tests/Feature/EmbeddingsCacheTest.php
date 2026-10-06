<?php
declare(strict_types=1);

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Exception\EmbeddingsCountMismatchException;
use Crustum\Ai\Prompts\EmbeddingsPrompt;
use Crustum\Ai\Responses\EmbeddingsResponse;

beforeEach(function (): void {
    if (!Cache::getConfig('array')) {
        Cache::setConfig('array', [
            'className' => 'Array',
        ]);
    }

    Configure::write('Ai.caching.embeddings.store', 'array');
});

afterEach(function (): void {
    Cache::clear('array');
    Configure::write('Ai.caching.embeddings.cache', false);
});

test('cache is used when enabled explicitly', function (): void {
    $calls = 0;

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    $request = fn(array $inputs): EmbeddingsResponse => Embeddings::for($inputs)->cache(3600)->generate();

    $request(['Hello']);
    $request(['Hello']);

    expect($calls)->toBe(1);
});

test('cache is used when enabled globally via config', function (): void {
    Configure::write('Ai.caching.embeddings.cache', true);

    $calls = 0;

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    $request = fn(array $inputs): EmbeddingsResponse => Embeddings::for($inputs)->generate();

    $request(['Hello']);
    $request(['Hello']);

    expect($calls)->toBe(1);
});

test('zero cache seconds bypasses an existing cached entry', function (): void {
    $calls = 0;

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    $request = fn(array $inputs, ?int $seconds = null): EmbeddingsResponse => Embeddings::for($inputs)->cache($seconds)->generate();

    $request(['Hello'], 3600);

    expect($calls)->toBe(1);

    $request(['Hello'], 0);

    expect($calls)->toBe(2);
});

test('negative cache seconds bypasses an existing cached entry', function (): void {
    $calls = 0;

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    $request = fn(array $inputs, ?int $seconds = null): EmbeddingsResponse => Embeddings::for($inputs)->cache($seconds)->generate();

    $request(['Hello'], 3600);

    expect($calls)->toBe(1);

    $request(['Hello'], -1);

    expect($calls)->toBe(2);
});

test('inputs that differ only in their boundaries do not share a cache entry', function (): void {
    $calls = 0;

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    $request = fn(array $inputs): EmbeddingsResponse => Embeddings::for($inputs)->cache(3600)->generate();

    $request(['a-b', 'c']);
    $request(['a', 'b-c']);

    expect($calls)->toBe(2);
});

test('identical string inputs still share a cache entry', function (): void {
    $calls = 0;

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    $request = fn(array $inputs): EmbeddingsResponse => Embeddings::for($inputs)->cache(3600)->generate();

    $request(['a-b', 'c']);
    $request(['a-b', 'c']);

    expect($calls)->toBe(1);
});

test('reordered string inputs share per-input cache entries under individual caching', function (): void {
    $calls = 0;

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    $request = fn(array $inputs): EmbeddingsResponse => Embeddings::for($inputs)->cache(3600, individually: true)->generate();

    $request(['a', 'b']);
    $request(['b', 'a']);

    expect($calls)->toBe(1);
});

test('individual caching only fetches the inputs that are not yet cached', function (): void {
    $calls = 0;
    $seen = [];

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls, &$seen): array {
        $calls++;
        $seen[] = $prompt->inputs;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    $request = fn(array $inputs): EmbeddingsResponse => Embeddings::for($inputs)->cache(3600, individually: true)->generate();

    $request(['a', 'b']);
    $request(['a', 'c']);

    expect($calls)->toBe(2);
    expect($seen[1])->toBe(['c']);
});

test('individual caching reuses the cached input across differing requests', function (): void {
    $calls = 0;

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    $request = fn(array $inputs): EmbeddingsResponse => Embeddings::for($inputs)->cache(3600, individually: true)->generate();

    $first = $request(['a', 'b']);
    $second = $request(['b', 'a', 'c']);

    expect($calls)->toBe(2);
    expect($first->embeddings)->toHaveCount(2);
    expect($second->embeddings)->toHaveCount(3);
});

test('individual caching throws when the provider returns an embedding count mismatch', function (): void {
    Embeddings::fake(fn(EmbeddingsPrompt $prompt): array => [array_fill(0, $prompt->dimensions, 0.1)]);

    expect(fn(): EmbeddingsResponse => Embeddings::for(['a', 'b'])->cache(3600, individually: true)->generate())
        ->toThrow(EmbeddingsCountMismatchException::class);
});

test('individual caching is enabled when the config value is missing', function (): void {
    Configure::write('Ai.caching.embeddings', [
        'cache' => false,
        'store' => 'array',
    ]);

    $calls = 0;

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return array_map(fn(): array => array_fill(0, $prompt->dimensions, 0.1), $prompt->inputs);
    });

    Embeddings::for(['a', 'b'])->cache(3600)->generate();
    Embeddings::for(['b'])->cache(3600)->generate();

    expect($calls)->toBe(1);
});
