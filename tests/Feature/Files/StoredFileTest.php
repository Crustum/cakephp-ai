<?php
declare(strict_types=1);

use Crustum\Ai\Files\StoredAudio;
use Crustum\Ai\Files\StoredDocument;
use Crustum\Ai\Test\Support\Storage\LocalDisk;

test('mime type is null when the disk cannot detect it', function (string $class): void {
    LocalDisk::fake('files');

    try {
        expect((new $class('missing.bin', 'files'))->mimeType())->toBeNull();
    } finally {
        LocalDisk::cleanup('files');
    }
})->with([StoredDocument::class, StoredAudio::class]);
