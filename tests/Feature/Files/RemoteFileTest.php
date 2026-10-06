<?php
declare(strict_types=1);

use Crustum\Ai\Files\RemoteImage;

test('mime type falls back to the response content type', function (): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('bytes', 200, ['Content-Type' => 'image/webp; charset=binary']),
    ]);

    expect((new RemoteImage('https://example.com/photo'))->mimeType())->toBe('image/webp');
});

test('mime type is null when the response declares none', function (): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('bytes', 200, ['Content-Type' => '']),
    ]);

    expect((new RemoteImage('https://example.com/photo'))->mimeType())->toBeNull();
});

test('declared mime type wins without fetching the url', function (): void {
    aiHttpFake();

    expect((new RemoteImage('https://example.com/photo', 'image/png'))->mimeType())->toBe('image/png');

    aiAssertHttpNothingSent();
});

test('a failed fetch throws instead of inlining the error body', function (): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('Not Found', 404),
    ]);

    (new RemoteImage('https://example.com/missing.png'))->content();
})->throws(RuntimeException::class);

test('the remote url is only fetched once', function (): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $image = new RemoteImage('https://example.com/photo.png');

    $image->mimeType();
    $image->content();

    aiAssertHttpSentCount(1);
});
