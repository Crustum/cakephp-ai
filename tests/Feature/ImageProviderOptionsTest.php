<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Image;
use Crustum\Ai\Job\GenerateImageJob;
use Crustum\Ai\Prompts\QueuedImagePrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

function fakeImageOptionsResponse(): array
{
    return [
        '*' => aiHttpResponse([
            'data' => [['b64_json' => base64_encode('fake-image')]],
        ]),
    ];
}

test('flat provider options are sent on the image request', function (): void {
    aiHttpFake(fakeImageOptionsResponse());

    Image::of('A red apple')
        ->withProviderOptions(['background' => 'transparent'])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['background'] === 'transparent');
});

test('provider options may not override the core image request payload', function (): void {
    aiHttpFake(fakeImageOptionsResponse());

    Image::of('A red apple')
        ->withProviderOptions(['model' => 'hijacked', 'prompt' => 'hijacked'])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-image-1' && $body['prompt'] === 'A red apple';
    });
});

test('closure provider options receive the resolved image provider', function (): void {
    aiHttpFake(fakeImageOptionsResponse());

    $seen = [];

    Image::of('A red apple')
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return ['background' => 'transparent'];
        })
        ->generate(provider: 'openai', model: 'gpt-image-1');

    expect($seen)->toBe(['openai']);

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['background'] === 'transparent');
});

test('flat provider options are recorded on the queued image prompt fake', function (): void {
    Image::fake();

    Image::of('A red apple')
        ->withProviderOptions(['background' => 'transparent'])
        ->queue(provider: 'openai');

    Image::assertQueued(
        fn(QueuedImagePrompt $prompt): bool => $prompt->providerOptions === ['background' => 'transparent'],
    );
});

test('closure provider options survive queue serialization round-trip', function (): void {
    aiHttpFake(fakeImageOptionsResponse());

    $pending = Image::of('A red apple')
        ->withProviderOptions(fn(Provider $provider): array => ['background' => 'transparent']);

    $payload = GenerateImageJob::payload($pending, 'openai', 'gpt-image-1');
    $payload = json_decode(json_encode($payload), true);

    (new GenerateImageJob())->run($payload);

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['background'] === 'transparent');
});
