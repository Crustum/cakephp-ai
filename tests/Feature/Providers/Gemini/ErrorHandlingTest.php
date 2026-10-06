<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Http\Client\Exception\NetworkException;
use Cake\Http\Client\Request;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\ProviderConnectionException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Providers\GeminiProvider;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

test('http error response throws request exception', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'error' => [
                'code' => 400,
                'message' => 'Invalid value at contents',
                'status' => 'INVALID_ARGUMENT',
            ],
        ], 400),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'error' => [
                'code' => 429,
                'message' => 'Resource has been exhausted',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ], 429),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'error' => [
                'code' => 503,
                'message' => 'The model is overloaded. Please try again later.',
                'status' => 'UNAVAILABLE',
            ],
        ], 503),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(ProviderOverloadedException::class);

test('transient upstream errors fail over as overloaded', function (int $status): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'error' => [
                'code' => $status,
                'message' => 'The service is temporarily unavailable.',
            ],
        ], $status),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );
})->with([502, 504, 520, 522, 524])->throws(ProviderOverloadedException::class);

test('connection failure throws a failoverable provider connection exception', function (): void {
    aiHttpFake(fn(): never => throw new NetworkException(
        'Connection refused',
        new Request('https://generativelanguage.googleapis.com'),
    ));

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(ProviderConnectionException::class, 'Could not connect to AI provider [gemini].');

test('connection failure fails over to the next provider', function (): void {
    Configure::write('Ai.providers.primary', ['className' => GeminiProvider::class, 'driver' => 'gemini', 'key' => 'test-key']);
    Configure::write('Ai.providers.backup', ['className' => GeminiProvider::class, 'driver' => 'gemini', 'key' => 'test-key']);

    $backup = $this->fakeTextResponse('Recovered on the backup provider');

    $attempts = 0;

    aiHttpFake(function () use (&$attempts, $backup): AiHttpResponseDefinition {
        $attempts++;

        if ($attempts === 1) {
            throw new NetworkException(
                'Connection refused',
                new Request('https://generativelanguage.googleapis.com'),
            );
        }

        return $backup;
    });

    $response = (new AssistantAgent())->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Recovered on the backup provider');
});

test('error in 200 response throws ai exception', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'error' => [
                'code' => 'internal',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(AiException::class, 'Gemini Error');

test('a failed interaction throws ai exception', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'id' => 'int_123',
            'status' => 'failed',
            'steps' => [],
            'errors' => [['code' => 'internal', 'message' => 'The model stopped responding.']],
        ]),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(AiException::class, 'Gemini Error: [internal] The model stopped responding.');

test('a withheld answer is reported as a content filter finish reason', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'id' => 'int_123',
            'status' => 'failed',
            'steps' => [],
            'errors' => [['code' => 'safety', 'message' => 'The response was blocked.']],
        ]),
    ]);

    $response = (new AssistantAgent())->prompt('Hi', provider: 'gemini');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::ContentFilter);
});
