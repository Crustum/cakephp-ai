<?php
declare(strict_types=1);

use Crustum\Ai\Files\Base64Document;

test('base64 document rejects empty content', function (): void {
    new Base64Document('');
})->throws(InvalidArgumentException::class, 'Base64 document content cannot be empty.');
