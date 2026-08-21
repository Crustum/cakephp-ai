<?php
declare(strict_types=1);

use Crustum\Ai\Files\Audio;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Files\Image;
use Crustum\Ai\Files\S3Document;
use Crustum\Ai\Files\Video;

test('s3 document toArray reflects url bucket owner and mime', function (): void {
    $array = Document::fromS3('s3://my-bucket/path/report.pdf', '123456789012', 'application/pdf')
        ->as('report.pdf')
        ->toArray();

    expect($array)->toBe([
        'type' => 's3-document',
        'name' => 'report.pdf',
        'url' => 's3://my-bucket/path/report.pdf',
        'bucket_owner' => '123456789012',
        'mime' => 'application/pdf',
    ]);
});

test('s3 document name falls back to basename from s3 url', function (): void {
    $array = (new S3Document('s3://my-bucket/path/report.pdf'))->toArray();

    expect($array['name'])->toBe('report.pdf');
});

test('s3 document content cannot be read directly', function (): void {
    expect(fn(): string => (new S3Document('s3://my-bucket/path/report.pdf'))->content())
        ->toThrow(InvalidArgumentException::class, 'S3Document cannot be read directly.');
});

test('stored document toArray falls back to basename without touching the filesystem', function (): void {
    $array = Document::fromStorage('invoices/invoice-2026-04.pdf', 'docs')->toArray();

    expect($array)->toBe([
        'type' => 'stored-document',
        'name' => 'invoice-2026-04.pdf',
        'path' => 'invoices/invoice-2026-04.pdf',
        'filesystem' => 'docs',
    ]);
});

test('stored audio toArray falls back to basename', function (): void {
    $array = Audio::fromStorage('clips/hello.mp3', 'docs')->toArray();

    expect($array['type'])->toBe('stored-audio');
    expect($array['name'])->toBe('hello.mp3');
    expect($array)->not->toHaveKey('mime');
});

test('stored image toArray falls back to basename', function (): void {
    $array = Image::fromStorage('photos/avatar.png', 'docs')->toArray();

    expect($array['type'])->toBe('stored-image');
    expect($array['name'])->toBe('avatar.png');
    expect($array)->not->toHaveKey('mime');
});

test('stored video toArray falls back to basename', function (): void {
    $array = Video::fromStorage('clips/demo.mp4', 'docs')->toArray();

    expect($array['type'])->toBe('stored-video');
    expect($array['name'])->toBe('demo.mp4');
    expect($array)->not->toHaveKey('mime');
});

test('local image toArray uses basename and the raw mime property', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'local-image');
    file_put_contents($path, 'data');

    try {
        $array = Image::fromPath($path)->toArray();

        expect($array['type'])->toBe('local-image');
        expect($array['name'])->toBe(basename($path));
        expect($array['path'])->toBe($path);
        expect($array['mime'])->toBeNull();
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('local image toArray returns the explicitly set mime type', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'local-image');
    file_put_contents($path, 'data');

    try {
        $array = Image::fromPath($path)->withMimeType('image/custom')->toArray();

        expect($array['mime'])->toBe('image/custom');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('local video toArray uses basename and the raw mime property', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'local-video');
    file_put_contents($path, 'data');

    try {
        $array = Video::fromPath($path)->toArray();

        expect($array['type'])->toBe('local-video');
        expect($array['name'])->toBe(basename($path));
        expect($array['path'])->toBe($path);
        expect($array['mime'])->toBeNull();
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('local video toArray returns the explicitly set mime type', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'local-video');
    file_put_contents($path, 'data');

    try {
        $array = Video::fromPath($path)->withMimeType('video/custom')->toArray();

        expect($array['mime'])->toBe('video/custom');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('base64 document toArray reflects name and mime', function (): void {
    $doc = Document::fromString('hello world', 'text/plain')->as('greeting.txt');

    expect($doc->toArray())->toMatchArray([
        'type' => 'base64-document',
        'name' => 'greeting.txt',
        'mime' => 'text/plain',
    ]);
});

test('base64 video toArray reflects name and mime', function (): void {
    $video = Video::fromBase64(base64_encode('video'), 'video/mp4')->as('demo.mp4');

    expect($video->toArray())->toMatchArray([
        'type' => 'base64-video',
        'name' => 'demo.mp4',
        'mime' => 'video/mp4',
    ]);
});

test('remote document toArray never issues an HTTP request', function (): void {
    aiHttpFake();

    $array = Document::fromUrl('https://example.com/signed/private.pdf')->toArray();

    expect($array)->toBe([
        'type' => 'remote-document',
        'name' => 'private.pdf',
        'url' => 'https://example.com/signed/private.pdf',
        'mime' => null,
    ]);

    aiAssertHttpNothingSent();
});

test('remote image toArray returns the explicitly set mime type without HTTP calls', function (): void {
    aiHttpFake();

    $array = Image::fromUrl('https://example.com/avatar.png')->withMimeType('image/png')->toArray();

    expect($array['mime'])->toBe('image/png');
    expect($array['name'])->toBe('avatar.png');

    aiAssertHttpNothingSent();
});

test('remote video toArray returns the explicitly set mime type without HTTP calls', function (): void {
    aiHttpFake();

    $array = Video::fromUrl('https://example.com/demo.mp4')->withMimeType('video/mp4')->toArray();

    expect($array['mime'])->toBe('video/mp4');
    expect($array['name'])->toBe('demo.mp4');

    aiAssertHttpNothingSent();
});

test('remote document toArray handles urls without a path component', function (): void {
    aiHttpFake();

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        $array = Document::fromUrl('https://example.com')->toArray();
    } finally {
        restore_error_handler();
    }

    expect($array['name'])->toBe('');
    expect($array['mime'])->toBeNull();

    aiAssertHttpNothingSent();
});

test('local image toArray does not touch the filesystem', function (): void {
    $path = '/tmp/this-file-definitely-does-not-exist-' . uniqid() . '.png';

    $array = Image::fromPath($path)->toArray();

    expect($array)->toBe([
        'type' => 'local-image',
        'name' => basename($path),
        'path' => $path,
        'mime' => null,
    ]);
});
