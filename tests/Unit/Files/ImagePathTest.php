<?php
declare(strict_types=1);

use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\StoredImage;

test('local image rejects empty path', function (): void {
    new LocalImage('');
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');

test('local image rejects whitespace-only path', function (): void {
    new LocalImage("  \t\n");
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');

test('stored image rejects empty path', function (): void {
    new StoredImage('');
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');

test('stored image rejects whitespace-only path', function (): void {
    new StoredImage("  \t\n");
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');
