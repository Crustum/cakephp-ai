<?php
declare(strict_types=1);

use Crustum\Ai\Image;
use Crustum\Ai\Test\Support\Skips\ApiKey;

test('images can be generated', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $response = Image::of('Donut sitting on a kitchen counter.')
        ->generate(provider: $provider, model: $model);

    expect($response->meta->provider)->toEqual($provider);
})->with('image-providers');

test('images can be generated with square size', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $response = Image::of('Donut sitting on a kitchen counter.')
        ->square()
        ->generate(provider: $provider, model: $model);

    expect($response->meta->provider)->toEqual($provider);
})->with('image-providers');
