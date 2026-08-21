<?php
declare(strict_types=1);

use Crustum\Ai\Files\Base64Image;

test('base64 image rejects empty content', function (): void {
    new Base64Image('');
})->throws(InvalidArgumentException::class, 'Base64 image content cannot be empty.');
