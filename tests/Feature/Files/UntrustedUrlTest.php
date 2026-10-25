<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\UntrustedUrl;
use Crustum\Ai\Http\HttpClientFactory;

test('a remote file pointing at a blocked address is never fetched', function (string $url): void {
    aiHttpFake();

    expect(fn(): string => (new RemoteImage($url))->content())->toThrow(InvalidArgumentException::class);

    aiAssertHttpNothingSent();
})->with([
    'metadata endpoint' => 'http://169.254.169.254/latest/meta-data/',
    'loopback' => 'http://127.0.0.1:8000/secret',
    'private range' => 'http://10.0.0.5/admin',
    'cgnat range' => 'http://100.64.1.1/',
    'localhost' => 'http://localhost/',
    'local suffix' => 'http://printer.local/',
    'trailing dot' => 'http://localhost./',
    'ipv6 loopback' => 'http://[::1]/',
    'ipv4 mapped ipv6' => 'http://[::ffff:10.0.0.1]/',
    'nat64 embedded ipv4' => 'http://[64:ff9b::a00:1]/',
    'local-use nat64' => 'http://[64:ff9b:1::a00:1]/',
    'unique local ipv6' => 'http://[fd00::1]/',
    'unsupported scheme' => 'ftp://example.com/file',
]);

test('a hostname resolving to a private address is blocked', function (): void {
    aiHttpFake();

    UntrustedUrl::resolveUsing(fn(): array => ['93.184.216.34', '169.254.169.254']);

    expect(fn(): string => (new RemoteImage('https://rebinding.example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class);

    aiAssertHttpNothingSent();
});

test('the connection is pinned to the validated addresses', function (): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('bytes'),
    ]);

    (new RemoteImage('https://example.com/photo.png'))->content();

    $history = HttpClientFactory::history();
    $pinned = $history[0]['options']['curl'][CURLOPT_RESOLVE] ?? null;

    expect($pinned)->toBe(['example.com:443:93.184.216.34']);
});

test('a public ip literal is fetched without pinning', function (string $url): void {
    aiHttpFake([
        'http://93.184.216.34/*' => aiHttpResponse('bytes'),
        'http://[64:ff9b::808:808]/*' => aiHttpResponse('bytes'),
    ]);

    expect((new RemoteImage($url))->content())->toBe('bytes');

    $history = HttpClientFactory::history();
    $pinned = $history[0]['options']['curl'][CURLOPT_RESOLVE] ?? null;

    expect($pinned)->toBe([]);
})->with([
    'ipv4' => 'http://93.184.216.34/photo.png',
    'nat64 embedded public ipv4' => 'http://[64:ff9b::808:808]/photo.png',
]);

test('an allowed host skips the private address check', function (): void {
    Configure::write('Ai.remote_files.allowed_hosts', ['minio']);

    UntrustedUrl::resolveUsing(fn(): array => ['172.18.0.2']);

    aiHttpFake(['http://minio:9000/*' => aiHttpResponse('bytes', 200)]);

    expect((new RemoteImage('http://minio:9000/bucket/photo.png'))->content())->toBe('bytes');
});

test('a redirect to a blocked address is not followed', function (): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    expect(fn(): string => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class);

    aiAssertHttpSentCount(1);
});

test('a redirect to a public address is followed', function (): void {
    aiHttpFake([
        'example.com/photo.png' => aiHttpResponse('', 301, ['Location' => '/moved/photo.png']),
        'example.com/moved/*' => aiHttpResponse('bytes', 200),
    ]);

    expect((new RemoteImage('https://example.com/photo.png'))->content())->toBe('bytes');

    aiAssertHttpSentCount(2);
});

test('too many redirects throws', function (): void {
    aiHttpFake(['example.com/*' => aiHttpResponse('', 302, ['Location' => 'https://example.com/again'])]);

    expect(fn(): string => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class, 'redirected too many times');

    aiAssertHttpSentCount(6);
});

test('a body larger than the size limit is rejected', function (): void {
    Configure::write('Ai.remote_files.max_size', 1);

    aiHttpFake([
        'example.com/*' => aiHttpResponse(str_repeat('a', 1025)),
    ]);

    expect(fn(): string => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class, 'exceeds the maximum size of 1 kilobytes');

    Configure::delete('Ai.remote_files.max_size');
});

test('a declared length larger than the size limit is rejected before the body is read', function (): void {
    Configure::write('Ai.remote_files.max_size', 1);

    aiHttpFake([
        'example.com/*' => aiHttpResponse('bytes', 200, ['Content-Length' => '1025']),
    ]);

    expect(fn(): string => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class, 'exceeds the maximum size of 1 kilobytes');

    aiAssertHttpSentCount(1);

    Configure::delete('Ai.remote_files.max_size');
});

test('the size limit defaults to 100 megabytes', function (): void {
    Configure::delete('Ai.remote_files.max_size');

    aiHttpFake([
        'example.com/*' => aiHttpResponse('bytes', 200, ['Content-Length' => (string)(100 * 1024 * 1024 + 1)]),
    ]);

    expect(fn(): string => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class, 'exceeds the maximum size of 102400 kilobytes');
});

test('a body within the size limit is fetched', function (): void {
    Configure::write('Ai.remote_files.max_size', 1);

    aiHttpFake([
        'example.com/*' => aiHttpResponse(str_repeat('b', 1024)),
    ]);

    expect((new RemoteImage('https://example.com/photo.png'))->content())->toBe(str_repeat('b', 1024));

    Configure::delete('Ai.remote_files.max_size');
});

test('a transport failure unrelated to the size limit is rethrown', function (): void {
    aiHttpFake(fn() => throw new RuntimeException('cURL error 7: Failed to connect'));

    expect(fn(): string => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(RuntimeException::class, 'Failed to connect');
});
