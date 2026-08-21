<?php
declare(strict_types=1);

use Crustum\Ai\Files\StoredVideo;
use Crustum\Ai\Filesystem\FilesystemRegistry;
use Crustum\Ai\Filesystem\LocalFlysystem;
use Crustum\Ai\Test\Support\Storage\LocalDisk;
use Crustum\Ai\Test\Support\Storage\TmpCleanup;

test('stored video reads content through league flysystem', function (): void {
    LocalDisk::fake('clips');
    LocalDisk::put('clips', 'demo.mp4', 'video-bytes');

    try {
        $video = new StoredVideo('demo.mp4', 'clips');

        expect($video->content())->toBe('video-bytes');
    } finally {
        LocalDisk::cleanup('clips');
    }
});

test('stored video can use a registered flysystem operator', function (): void {
    $root = TMP . 'ai_test_registered_fs';

    if (is_dir($root)) {
        TmpCleanup::removeDirectory($root);
    }

    $filesystem = LocalFlysystem::create($root);
    $filesystem->write('remote.mp4', 'from-operator');
    FilesystemRegistry::register('custom', $filesystem);

    try {
        expect((new StoredVideo('remote.mp4', 'custom'))->content())->toBe('from-operator');
    } finally {
        FilesystemRegistry::forget('custom');
        TmpCleanup::removeDirectory($root);
    }
});

test('stored video throws when file is missing on flysystem', function (): void {
    LocalDisk::fake('clips');

    try {
        (new StoredVideo('missing.mp4', 'clips'))->content();
    } finally {
        LocalDisk::cleanup('clips');
    }
})->throws(RuntimeException::class, 'File [missing.mp4] does not exist on filesystem [clips].');
