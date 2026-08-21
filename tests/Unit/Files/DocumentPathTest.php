<?php
declare(strict_types=1);

use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Files\StoredDocument;

test('local document rejects empty path', function (): void {
    new LocalDocument('');
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');

test('local document rejects whitespace-only path', function (): void {
    new LocalDocument("  \t\n");
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');

test('stored document rejects empty path', function (): void {
    new StoredDocument('');
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');

test('stored document rejects whitespace-only path', function (): void {
    new StoredDocument("  \t\n");
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');
