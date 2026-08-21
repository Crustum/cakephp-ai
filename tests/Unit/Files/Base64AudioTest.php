<?php
declare(strict_types=1);

use Crustum\Ai\Files\Base64Audio;

test('base64 audio rejects empty content', function (): void {
    new Base64Audio('');
})->throws(InvalidArgumentException::class, 'Base64 audio content cannot be empty.');
