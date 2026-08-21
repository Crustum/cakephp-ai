<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\ImageResponse;

test('mime falls back to image/png when no mime type is set', function (): void {
    expect((new GeneratedImage('aGVsbG8='))->mime())->toBe('image/png');
    expect((new GeneratedImage('aGVsbG8=', 'image/webp'))->mime())->toBe('image/webp');
});

test('toHtml renders an img tag with the image mime type', function (): void {
    $response = new ImageResponse(
        collection([new GeneratedImage('aGVsbG8=', 'image/jpeg')]),
        new Usage(),
        new Meta('openai', 'gpt-image-1'),
    );

    expect($response->toHtml())->toBe(
        '<img src="data:image/jpeg;base64,aGVsbG8=" alt="" />',
    );
});

test('toHtml falls back to image/png when the image has no mime type', function (): void {
    $response = new ImageResponse(
        collection([new GeneratedImage('aGVsbG8=')]),
        new Usage(),
        new Meta('openai', 'gpt-image-1'),
    );

    expect($response->toHtml())->toBe(
        '<img src="data:image/png;base64,aGVsbG8=" alt="" />',
    );
});

test('toHtml escapes the alt attribute', function (): void {
    $response = new ImageResponse(
        collection([new GeneratedImage('aGVsbG8=', 'image/png')]),
        new Usage(),
        new Meta('openai', 'gpt-image-1'),
    );

    expect($response->toHtml('"><script>alert(1)</script>'))->toBe(
        '<img src="data:image/png;base64,aGVsbG8=" alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;" />',
    );
});

test('firstImage throws when the response contains no images', function (): void {
    $response = new ImageResponse(
        collection([]),
        new Usage(),
        new Meta('openai', 'gpt-image-1'),
    );

    expect(fn(): GeneratedImage => $response->firstImage())
        ->toThrow(RuntimeException::class, 'The image response does not contain any images.');
});
