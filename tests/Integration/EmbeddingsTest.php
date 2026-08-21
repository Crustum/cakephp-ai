<?php
declare(strict_types=1);

use Crustum\Ai\Embeddings;
use Crustum\Ai\Event\EmbeddingsGenerated;
use Crustum\Ai\Event\GeneratingEmbeddings;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Test\Support\Skips\ApiKey;

test('embeddings can be generated', function (string $provider, string $apiKey, int $dimensions): void {
    ApiKey::required($apiKey);

    $recorder = EventRecorder::start([GeneratingEmbeddings::class, EmbeddingsGenerated::class]);

    $response = Embeddings::for(['I love to watch Star Trek.'])->generate(provider: $provider);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount($dimensions)
        ->and($response->meta->provider)->toEqual($provider);

    $recorder->assertMatches(GeneratingEmbeddings::class, fn(GeneratingEmbeddings $event): bool => $event->prompt->timeout === 30);
    $recorder->assertMatches(EmbeddingsGenerated::class, fn(EmbeddingsGenerated $event): bool => $event->prompt->timeout === 30);
})->with('embedding-providers');

test('embeddings can be generated with custom dimensions', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $response = Embeddings::for(['test text'])
        ->dimensions(256)
        ->generate(provider: $provider);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount(256);
})->with('embedding-providers');

test('queued embeddings with closure provider options run end-to-end through a real queue driver', function (string $provider, string $apiKey, int $dimensions): void {
    ApiKey::required($apiKey);
})->with('embedding-providers')->skip('Cake queue worker integration is not implemented yet.');
