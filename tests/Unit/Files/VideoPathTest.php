<?php
declare(strict_types=1);

use Crustum\Ai\Files\LocalVideo;
use Crustum\Ai\Files\StoredVideo;

test('local video rejects empty path', function (): void {
    new LocalVideo('');
})->throws(InvalidArgumentException::class, 'Video file path cannot be empty.');

test('local video rejects whitespace-only path', function (): void {
    new LocalVideo("  \t\n");
})->throws(InvalidArgumentException::class, 'Video file path cannot be empty.');

test('stored video rejects empty path', function (): void {
    new StoredVideo('');
})->throws(InvalidArgumentException::class, 'Video file path cannot be empty.');

test('stored video rejects whitespace-only path', function (): void {
    new StoredVideo("  \t\n");
})->throws(InvalidArgumentException::class, 'Video file path cannot be empty.');
