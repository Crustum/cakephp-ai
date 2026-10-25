<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Event\AddingFileToStore;
use Crustum\Ai\Event\AddingFileToStoreFailed;
use Crustum\Ai\Event\CreatingStore;
use Crustum\Ai\Event\CreatingStoreFailed;
use Crustum\Ai\Event\RemovingFileFromStore;
use Crustum\Ai\Event\RemovingFileFromStoreFailed;
use Crustum\Ai\Event\StoringFile;
use Crustum\Ai\Event\StoringFileFailed;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Files\ProviderDocument;
use Crustum\Ai\Test\Support\Event\EventRecorder;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('file and store failures dispatch an event mirroring the starting event and rethrow', function (Closure $invoke, string $startingEvent, string $failureEvent): void {
    $recorder = EventRecorder::start([$startingEvent, $failureEvent]);

    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(['error' => ['message' => 'Server error.']], 500),
    ]);

    $caught = null;

    try {
        $invoke(Ai::manager()->provider('openai'));
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }

    $started = array_values(array_filter($recorder->events(), fn(object $event): bool => $event instanceof $startingEvent));
    $failed = array_values(array_filter($recorder->events(), fn(object $event): bool => $event instanceof $failureEvent));

    expect($caught)->not->toBeNull()
        ->and($started)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception)->toBe($caught)
        ->and(array_diff_key(get_object_vars($failed[0]), ['exception' => true]))->toBe(get_object_vars($started[0]));
})->with([
    'storing a file' => [
        fn($provider) => $provider->putFile(Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')),
        StoringFile::class,
        StoringFileFailed::class,
    ],
    'creating a store' => [
        fn($provider) => $provider->createStore('Knowledge Base', 'Product docs'),
        CreatingStore::class,
        CreatingStoreFailed::class,
    ],
    'adding a file to a store' => [
        fn($provider) => $provider->addFileToStore('vs-123', new ProviderDocument('file-abc123')),
        AddingFileToStore::class,
        AddingFileToStoreFailed::class,
    ],
    'removing a file from a store' => [
        fn($provider) => $provider->removeFileFromStore('vs-123', 'file-abc123'),
        RemovingFileFromStore::class,
        RemovingFileFromStoreFailed::class,
    ],
]);
