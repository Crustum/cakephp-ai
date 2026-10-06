<?php
declare(strict_types=1);

use Crustum\Ai\Embeddings;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Prompts\EmbeddingsPrompt;
use Crustum\Ai\Providers\CohereProvider;
use Crustum\Ai\Text;

test('embeddings can be generated from text stringable', function (): void {
    Embeddings::fake(fn(EmbeddingsPrompt $prompt): array => array_map(
        fn(string $input): array => array_fill(0, 3, strlen($input) / 10),
        $prompt->inputs,
    ));

    $embeddings = Text::of('Hello world')->toEmbeddings();

    expect($embeddings)->toEqual([1.1, 1.1, 1.1]);

    Embeddings::assertGenerated(fn(EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Hello world']);
});

test('embeddings can be generated from static text helper', function (): void {
    Embeddings::fake([[[0.1, 0.2, 0.3]]]);

    $embeddings = Text::toEmbeddings('Hello world');

    expect($embeddings)->toEqual([0.1, 0.2, 0.3]);
});

test('text embeddings macro passes through options', function (): void {
    Embeddings::fake();

    Text::of('Hello world')->toEmbeddings(
        provider: Lab::Cohere,
        dimensions: 8,
        model: 'custom-model',
        timeout: 45,
    );

    Embeddings::assertGenerated(fn(EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Hello world']
        && $prompt->provider instanceof CohereProvider
        && $prompt->dimensions === 8
        && $prompt->model === 'custom-model'
        && $prompt->timeout === 45);
});
