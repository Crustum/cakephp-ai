<?php
declare(strict_types=1);

use Cake\Event\EventManager;
use Crustum\Ai\Audio;
use Crustum\Ai\Classification;
use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Event\AudioFailed;
use Crustum\Ai\Event\ClassificationFailed;
use Crustum\Ai\Event\Classifying;
use Crustum\Ai\Event\EmbeddingsFailed;
use Crustum\Ai\Event\EmbeddingsGenerated;
use Crustum\Ai\Event\GeneratingAudio;
use Crustum\Ai\Event\GeneratingEmbeddings;
use Crustum\Ai\Event\GeneratingImage;
use Crustum\Ai\Event\GeneratingTranscription;
use Crustum\Ai\Event\ImageFailed;
use Crustum\Ai\Event\Reranking;
use Crustum\Ai\Event\RerankingFailed;
use Crustum\Ai\Event\TranscriptionFailed;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Image;
use Crustum\Ai\Reranking as RerankingOperation;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\ClassificationResponse;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\ImageResponse;
use Crustum\Ai\Responses\RerankingResponse;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Transcription;

test('operation failures dispatch an event and rethrow the original exception', function (Closure $fake, Closure $invoke, string $startingEvent, string $failureEvent): void {
    $recorder = EventRecorder::start([$startingEvent, $failureEvent]);

    $exception = new RuntimeException('Provider failed.');
    $fake(fn() => throw $exception);

    $caught = null;

    try {
        $invoke();
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }

    $started = array_values(array_filter($recorder->events(), fn(object $event): bool => $event instanceof $startingEvent));
    $failed = array_values(array_filter($recorder->events(), fn(object $event): bool => $event instanceof $failureEvent));

    expect($caught)->toBe($exception)
        ->and($started)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->invocationId)->toBe($started[0]->invocationId)
        ->and($failed[0]->provider)->toBe($started[0]->provider)
        ->and($failed[0]->model)->toBe($started[0]->model)
        ->and($failed[0]->prompt)->toBe($started[0]->prompt)
        ->and($failed[0]->exception)->toBe($exception);
})->with('operations');

test('successful operations do not dispatch failure events', function (Closure $fake, Closure $invoke, string $startingEvent, string $failureEvent): void {
    $recorder = EventRecorder::start([$startingEvent, $failureEvent]);

    $fake([]);
    $invoke();

    $started = array_values(array_filter($recorder->events(), fn(object $event): bool => $event instanceof $startingEvent));
    $failed = array_values(array_filter($recorder->events(), fn(object $event): bool => $event instanceof $failureEvent));

    expect($started)->toHaveCount(1)
        ->and($failed)->toBeEmpty();
})->with('operations');

test('a throwing success listener does not report the operation as failed', function (): void {
    Embeddings::fake();

    $failed = [];

    $recorder = EventRecorder::start([EmbeddingsFailed::class]);
    EventRecorder::start([EmbeddingsGenerated::class]);

    EventManager::instance()->on(EmbeddingsGenerated::eventName(), function (): void {
        throw new RuntimeException('Listener failed.');
    });

    expect(fn(): EmbeddingsResponse => Embeddings::for(['Hello world'])->generate())->toThrow(RuntimeException::class, 'Listener failed.')
        ->and($failed)->toBeEmpty()
        ->and($recorder->events())->toBeEmpty();
});

test('a recovered failover reports only the attempt that failed', function (): void {
    $attempts = 0;

    Embeddings::fake(function () use (&$attempts): array {
        if ($attempts++ === 0) {
            throw RateLimitedException::forProvider('openai');
        }

        return [[0.1, 0.2]];
    });

    $recorder = EventRecorder::start([GeneratingEmbeddings::class, EmbeddingsFailed::class, EmbeddingsGenerated::class]);

    $response = Embeddings::for(['Hello world'])->generate(['openai', 'gemini']);

    $generating = array_values(array_filter($recorder->events(), fn(object $event): bool => $event instanceof GeneratingEmbeddings));
    $failed = array_values(array_filter($recorder->events(), fn(object $event): bool => $event instanceof EmbeddingsFailed));
    $generated = array_values(array_filter($recorder->events(), fn(object $event): bool => $event instanceof EmbeddingsGenerated));

    expect($response->embeddings)->toBe([[0.1, 0.2]])
        ->and($generating)->toHaveCount(2)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->invocationId)->toBe($generating[0]->invocationId)
        ->and($generated)->toHaveCount(1)
        ->and($generated[0]->invocationId)->toBe($generating[1]->invocationId);
});

dataset('operations', [
    'embeddings' => [
        Embeddings::fake(...),
        fn(): EmbeddingsResponse => Embeddings::for(['Hello world'])->generate(),
        GeneratingEmbeddings::class,
        EmbeddingsFailed::class,
    ],
    'image' => [
        Image::fake(...),
        fn(): ImageResponse => Image::of('A sunset')->generate(),
        GeneratingImage::class,
        ImageFailed::class,
    ],
    'audio' => [
        Audio::fake(...),
        fn(): AudioResponse => Audio::of('Hello world')->generate(),
        GeneratingAudio::class,
        AudioFailed::class,
    ],
    'transcription' => [
        Transcription::fake(...),
        fn(): TranscriptionResponse => Transcription::of(base64_encode('audio'))->generate(),
        GeneratingTranscription::class,
        TranscriptionFailed::class,
    ],
    'reranking' => [
        RerankingOperation::fake(...),
        fn(): RerankingResponse => RerankingOperation::of(['CakePHP is a framework'])->rerank('What is CakePHP?'),
        Reranking::class,
        RerankingFailed::class,
    ],
    'classification' => [
        Classification::fake(...),
        fn(): ClassificationResponse => Classification::of('Urgent request')->question('urgent', new Boolean('Is it urgent?'))->classify(),
        Classifying::class,
        ClassificationFailed::class,
    ],
]);
