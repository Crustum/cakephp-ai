<?php
declare(strict_types=1);

use Crustum\Ai\Http\Response;
use Crustum\Ai\Responses\ClassificationResponse;
use Crustum\Ai\Responses\Data\BooleanAnswer;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\ImageUsage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Responses\Data\RerankingUsage;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\TranscriptionSegment;
use Crustum\Ai\Responses\Data\TranscriptionUsage;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\ImageResponse;
use Crustum\Ai\Responses\RerankingResponse;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Test\Fixtures\Responses\SubclassedStep;
use Crustum\Ai\Test\Fixtures\Responses\SubclassedTextResponse;

test('serialization preserves private properties declared on text response subclasses', function (): void {
    $response = new SubclassedTextResponse('Hello', new TextUsage(1, 2), new Meta('anthropic', 'claude'));
    $response->rememberSecret('changed');
    $response->withRawResponse(new Response(200, [], '{}'));

    $restored = unserialize(serialize($response));

    expect($restored->secret())->toBe('changed')
        ->and($restored->text)->toBe('Hello')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves private properties declared on step subclasses', function (): void {
    $step = new SubclassedStep('Hello', [], [], FinishReason::Stop, new TextUsage(1, 2), new Meta('anthropic', 'claude'), '', []);
    $step->rememberSecret('changed');
    $step->withRawResponse(new Response(200, [], '{}'));

    $restored = unserialize(serialize($step));

    expect($restored->secret())->toBe('changed')
        ->and($restored->text)->toBe('Hello')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves classification answers and drops the raw response', function (): void {
    $response = (new ClassificationResponse(['is_urgent' => new BooleanAnswer(0.9)], new TextUsage(1, 2), new Meta('openai', 'gpt-6-luna')))
        ->withRawResponse(new Response(200, [], '{}'));

    $restored = unserialize(serialize($response));

    expect($restored->answer('is_urgent')->probability)->toBe(0.9)
        ->and($restored->usage->outputTokens)->toBe(2)
        ->and($restored->meta->model)->toBe('gpt-6-luna')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves embeddings and drops the raw response', function (): void {
    $response = (new EmbeddingsResponse([[0.1, 0.2]], new Usage(3), new Meta('openai', 'text-embedding-3-small')))
        ->withRawResponse(new Response(200, [], '{}'));

    $restored = unserialize(serialize($response));

    expect($restored->embeddings)->toBe([[0.1, 0.2]])
        ->and($restored->usage->inputTokens)->toBe(3)
        ->and($restored->meta->model)->toBe('text-embedding-3-small')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves transcription data and drops the raw response', function (): void {
    $response = (new TranscriptionResponse(
        'Hello, world!',
        collection([new TranscriptionSegment('Hello, world!', 'Speaker 1', 1.5, 3.25)]),
        new TranscriptionUsage(10, 4, audioSeconds: 3.25),
        new Meta('openai', 'gpt-4o-transcribe'),
    ))->withRawResponse(new Response(200, [], '{}'));

    $restored = unserialize(serialize($response));

    expect($restored->text)->toBe('Hello, world!')
        ->and($restored->segments->map(fn(TranscriptionSegment $segment): array => $segment->toArray())->toList())->toBe([
            ['text' => 'Hello, world!', 'speaker' => 'Speaker 1', 'start_seconds' => 1.5, 'end_seconds' => 3.25],
        ])
        ->and($restored->usage->inputTokens)->toBe(10)
        ->and($restored->usage->outputTokens)->toBe(4)
        ->and($restored->usage->audioSeconds)->toBe(3.25)
        ->and($restored->meta->provider)->toBe('openai')
        ->and($restored->meta->model)->toBe('gpt-4o-transcribe')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves image data and drops the raw response', function (): void {
    $response = (new ImageResponse(
        collection([new GeneratedImage(base64_encode('image-content'), 'image/webp')]),
        new ImageUsage(41, 1024, imageOutputTokens: 1000),
        new Meta('openai', 'gpt-image-1'),
    ))->withRawResponse(new Response(200, [], '{}'));

    $restored = unserialize(serialize($response));

    expect($restored->images)->toHaveCount(1)
        ->and($restored->firstImage()->content())->toBe('image-content')
        ->and($restored->firstImage()->mime())->toBe('image/webp')
        ->and($restored->usage->inputTokens)->toBe(41)
        ->and($restored->usage->outputTokens)->toBe(1024)
        ->and($restored->usage->imageOutputTokens)->toBe(1000)
        ->and($restored->meta->provider)->toBe('openai')
        ->and($restored->meta->model)->toBe('gpt-image-1')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves reranking data and drops the raw response', function (): void {
    $response = (new RerankingResponse(
        [new RankedDocument(1, 'CakePHP is a PHP framework', 0.95), new RankedDocument(0, 'React is a JS library', 0.12)],
        new RerankingUsage(320, 2.0),
        new Meta('cohere', 'rerank-v3.5'),
    ))->withRawResponse(new Response(200, [], '{}'));

    $restored = unserialize(serialize($response));

    expect($restored)->toBeInstanceOf(RerankingResponse::class)
        ->and($restored->results)->toEqual($response->results)
        ->and($restored->usage)->toEqual(new RerankingUsage(320, 2.0))
        ->and($restored->meta)->toEqual(new Meta('cohere', 'rerank-v3.5'))
        ->and($restored->raw)->toBeNull();
});
