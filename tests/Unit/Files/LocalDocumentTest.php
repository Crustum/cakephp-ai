<?php
declare(strict_types=1);

use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Test\Support\TestFile;

test('a document created from an upload can be serialized', function (): void {
    $document = LocalDocument::fromUploadedFile(
        TestFile::upload(__DIR__ . '/../../Fixtures/report.txt', 'report.txt', 'text/plain'),
    )->withProviderOptions(['purpose' => 'assistants']);

    $unserialized = unserialize(serialize($document));

    expect($unserialized->path)->toBe($document->path)
        ->and($unserialized->name())->toBe('report.txt')
        ->and($unserialized->mimeType())->toBe($document->mimeType())
        ->and($unserialized->providerOptions('openai'))->toBe(['purpose' => 'assistants']);
});
