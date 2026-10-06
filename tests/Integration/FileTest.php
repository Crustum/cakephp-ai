<?php
declare(strict_types=1);

use Cake\Http\Client\Exception\ClientException;
use Crustum\Ai\Event\FileDeleted;
use Crustum\Ai\Event\FileStored;
use Crustum\Ai\Event\StoringFile;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Test\Support\Skips\ApiKey;
use Crustum\Ai\Test\Support\Storage\LocalDisk;

test('can store files', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $recorder = EventRecorder::start([StoringFile::class, FileStored::class, FileDeleted::class]);

    $response = Document::fromString('Hello, World!', 'text/plain')->put(
        name: 'hello.txt',
        provider: $provider,
    );

    expect($response->id)->not->toBeEmpty();

    $recorder->assertDispatched(StoringFile::class);
    $recorder->assertDispatched(FileStored::class);

    Document::fromId($response->id)->delete(provider: $provider);

    $recorder->assertDispatched(FileDeleted::class);
})->with('file-providers');

test('can store files from local paths', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $response = Document::fromPath(__DIR__ . '/../Fixtures/document.txt')->put(
        name: 'document.txt',
        provider: $provider,
    );

    expect($response->id)->not->toBeEmpty();

    Document::fromId($response->id)->delete(provider: $provider);
})->with('file-providers');

test('can store files from storage paths', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    LocalDisk::put('local', 'document.txt', 'Hello, World!');

    try {
        $response = Document::fromStorage('document.txt', filesystem: 'local')->put(
            provider: $provider,
        );

        expect($response->id)->not->toBeEmpty();

        Document::fromId($response->id)->delete(provider: $provider);
    } finally {
        LocalDisk::cleanup('local');
    }
})->with('file-providers');

test('can store files from remote paths', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $stored = Document::fromUrl(
        'https://raw.githubusercontent.com/cakephp/cakephp/5.x/README.md',
    )->put(
        provider: $provider,
    );

    expect($stored->id)->not->toBeEmpty();

    $response = Document::fromId($stored->id)->get(provider: $provider);

    expect($response->mime)->toBeIn(['text/plain', 'text/markdown', null]);

    Document::fromId($response->id)->delete(provider: $provider);
})->with('file-providers');

test('exception is thrown if stored file does not exist', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    LocalDisk::fake('local');

    try {
        Document::fromStorage('missing-document.pdf', filesystem: 'local')->put(
            provider: $provider,
        );
    } finally {
        LocalDisk::cleanup('local');
    }
})->with('file-providers')->throws(RuntimeException::class);

test('can get files', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $stored = Document::fromString('Hello, World!', 'text/plain')->put(
        name: 'hello.txt',
        provider: $provider,
    );

    $response = Document::fromId($stored->id)->get(provider: $provider);

    expect($response->id)->toEqual($stored->id)
        ->and($response->mime)->toBeIn(['text/plain', null]);

    Document::fromId($response->id)->delete(provider: $provider);
})->with('file-providers');

test('can delete files', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $stored = Document::fromString('Hello, World!', 'text/plain')->put(
        name: 'hello.txt',
        provider: $provider,
    );

    Document::fromId($stored->id)->delete(provider: $provider);

    Document::fromId($stored->id)->get(provider: $provider);
})->with('file-providers')->throws(ClientException::class);
