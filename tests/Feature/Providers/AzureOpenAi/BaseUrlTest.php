<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

test('azure text requests use the v1 responses endpoint', function (): void {
    configureAzureProvider('https://my-resource.cognitiveservices.azure.com', deployment: 'gpt-4o');

    aiHttpFake(['*' => fakeAzureResponse('Hello from Azure')]);

    $response = agent()->prompt('Hello', provider: 'azure');

    expect($response->text)->toBe('Hello from Azure');

    azureAssertRequestSent('POST', 'https://my-resource.cognitiveservices.azure.com/openai/v1/responses');
});

test('azure requests do not include api-version query parameter', function (): void {
    configureAzureProvider('https://my-resource.cognitiveservices.azure.com');

    aiHttpFake(['*' => fakeAzureResponse()]);

    agent()->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => ! str_contains($request->url(), 'api-version'));
});

test('azure requests use api-key header not bearer token', function (): void {
    configureAzureProvider('https://my-resource.cognitiveservices.azure.com');

    aiHttpFake(['*' => fakeAzureResponse()]);

    agent()->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('api-key', 'test-key')
        && ! $request->hasHeader('Authorization', 'Bearer test-key'));
});

function configureAzureProvider(?string $url = null, ?string $apiVersion = null, string $deployment = 'gpt-4o'): void
{
    Configure::write('Ai.providers.azure', array_filter([

        ...(array)Configure::read('Ai.providers.azure'),
        'key' => 'test-key',
        'url' => $url,
        'deployment' => $deployment,
    ]));
}

function azureAssertRequestSent(string $method, string $url): void
{
    aiAssertHttpSent(function (AiHttpRequest $request) use ($method, $url): bool {
        $requestUrl = strtok($request->url(), '?');

        return $request->method() === $method
            && $requestUrl === $url;
    });
}
