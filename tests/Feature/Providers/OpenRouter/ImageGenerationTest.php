<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Image;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

function fakeOpenRouterImageResponse(string $mimeType = 'image/png', string $content = 'fake-image'): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'created' => 1748372400,
        'data' => [[
            'b64_json' => base64_encode($content),
            'media_type' => $mimeType,
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
        ],
    ]);
}

test('image request is sent to the images endpoint with model and prompt', function (): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'black-forest-labs/flux.2-pro');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://openrouter.ai/api/v1/images'
        && json_decode($request->body(), true) === [
            'model' => 'black-forest-labs/flux.2-pro',
            'prompt' => 'A blue circle',
        ]);
});

test('image request maps size to aspect_ratio', function (): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A sunset')->landscape()->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['aspect_ratio'] === '3:2');
});

test('image request maps quality to resolution', function (string $quality, string $expectedResolution): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A sunset')->quality($quality)->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['resolution'] === $expectedResolution);
})->with([
    'low maps to 1K' => ['low', '1K'],
    'medium maps to 2K' => ['medium', '2K'],
    'high maps to 4K' => ['high', '4K'],
]);

test('image_config provider options are sent as top level image fields', function (): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A sunset')
        ->withProviderOptions(['image_config' => ['aspect_ratio' => '21:9', 'image_size' => '2K']])
        ->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true) === [
        'aspect_ratio' => '21:9',
        'resolution' => '2K',
        'model' => 'google/gemini-2.5-flash-image',
        'prompt' => 'A sunset',
    ]);
});

test('image size and quality take precedence over image_config provider options', function (): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A sunset')
        ->landscape()
        ->quality('high')
        ->withProviderOptions(['image_config' => ['aspect_ratio' => '21:9', 'image_size' => '1K']])
        ->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['aspect_ratio'] === '3:2' && $body['resolution'] === '4K';
    });
});

test('image response is correctly parsed', function (): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse('image/webp', 'fake-image')]);

    $response = Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    expect($response->images)->toHaveCount(1)
        ->and($response->images->first()->image)->toBe(base64_encode('fake-image'))
        ->and($response->images->first()->mime)->toBe('image/webp')
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('google/gemini-2.5-flash-image');
});

test('usage tokens are parsed from response', function (): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    $response = Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    expect($response->usage->inputTokens)->toBe(10)
        ->and($response->usage->outputTokens)->toBe(20);
});

test('multiple images in response are all returned', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'data' => [
                ['b64_json' => base64_encode('first'), 'media_type' => 'image/jpeg'],
                ['b64_json' => base64_encode('second')],
            ],
        ]),
    ]);

    $response = Image::of('Two images')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    expect($response->images)->toHaveCount(2)
        ->and($response->images[0]->image)->toBe(base64_encode('first'))
        ->and($response->images[0]->mime)->toBe('image/jpeg')
        ->and($response->images[1]->image)->toBe(base64_encode('second'))
        ->and($response->images[1]->mime)->toBe('image/png');
});

test('empty images collection returned when response contains no images', function (): void {
    aiHttpFake(['openrouter.ai/*' => aiHttpResponse(['data' => []])]);

    $response = Image::of('A prompt')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    expect($response->images)->toHaveCount(0);
});

test('attachments are sent as input references', function (): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('Edit these images')->attachments([
        new Base64Image(base64_encode('source-image'), 'image/jpeg'),
        new RemoteImage('https://example.com/photo.jpg', 'image/jpeg'),
    ])->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['input_references'] === [
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode('source-image')]],
        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/photo.jpg']],
    ]);
});

test('request sends bearer token authorization', function (): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('image rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');
})->throws(RateLimitedException::class);

test('image overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');
})->throws(ProviderOverloadedException::class);

test('image http error response throws request exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid model',
            ],
        ], 400),
    ]);

    Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');
})->throws(RequestException::class);

test('falsy provider options are not filtered out of the image request', function (): void {
    aiHttpFake(['openrouter.ai/*' => fakeOpenRouterImageResponse()]);

    Image::of('A blue circle')
        ->withProviderOptions(['stream' => false])
        ->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['stream'] === false);
});
