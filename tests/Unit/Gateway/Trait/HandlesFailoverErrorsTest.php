<?php
declare(strict_types=1);

use Cake\Http\Client\Exception\ClientException;
use Crustum\Ai\Exception\InsufficientCreditsException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Http\Response;

beforeEach(function (): void {
    $this->gateway = new class
    {
        use HandlesFailoverErrorsTrait {
            withErrorHandling as public;
        }

        protected function overloadedStatusCodes(): array
        {
            return [529];
        }

        protected function insufficientCreditPatterns(): array
        {
            return ['credit balance', 'quota exceeded'];
        }
    };
});

function failoverableResponse(int $status, array $body = [], array $headers = []): Response
{
    return new Response(
        $status,
        ['Content-Type' => 'application/json', ...$headers],
        json_encode($body),
    );
}

test('overriding overloadedStatusCodes replaces the default 503', function (): void {
    expect(fn() => $this->gateway->withErrorHandling(
        'anthropic',
        fn(): Response => failoverableResponse(529),
    ))->toThrow(ProviderOverloadedException::class);

    expect(fn() => $this->gateway->withErrorHandling(
        'anthropic',
        fn(): Response => failoverableResponse(503),
    ))->toThrow(ClientException::class);
});

test('429 takes precedence over insufficient credit pattern matching', function (): void {
    expect(fn() => $this->gateway->withErrorHandling(
        'anthropic',
        fn(): Response => failoverableResponse(429, [
            'error' => ['message' => 'Your credit balance is too low.'],
        ]),
    ))->toThrow(RateLimitedException::class);
});

test('402 throws InsufficientCreditsException without requiring patterns', function (): void {
    $gateway = new class
    {
        use HandlesFailoverErrorsTrait {
            withErrorHandling as public;
        }
    };

    expect(fn(): mixed => $gateway->withErrorHandling(
        'deepseek',
        fn(): Response => failoverableResponse(402, [
            'error' => ['message' => 'Insufficient Balance'],
        ]),
    ))->toThrow(InsufficientCreditsException::class);
});

test('non-matching message is rethrown as the original RequestException', function (): void {
    expect(fn() => $this->gateway->withErrorHandling(
        'anthropic',
        fn(): Response => failoverableResponse(400, [
            'error' => ['message' => 'invalid prompt'],
        ]),
    ))->toThrow(ClientException::class);
});

test('a rate limited or overloaded request is retried once after its Retry-After delay', function (int $status): void {
    $attempts = 0;

    $result = $this->gateway->withErrorHandling('anthropic', function () use ($status, &$attempts): Response|string {
        if (++$attempts === 1) {
            return failoverableResponse($status, headers: ['Retry-After' => '0']);
        }

        return 'ok';
    });

    expect($result)->toBe('ok')
        ->and($attempts)->toBe(2);
})->with([429, 529]);

test('a request that is still rate limited after the retry fails over', function (): void {
    $attempts = 0;

    $request = function () use (&$attempts): Response {
        $attempts++;

        return failoverableResponse(429, headers: ['Retry-After' => '0']);
    };

    expect(fn() => $this->gateway->withErrorHandling('anthropic', $request))->toThrow(RateLimitedException::class);

    expect($attempts)->toBe(2);
});

test('a request is not retried without a short Retry-After on a failoverable status', function (int $status, array $headers): void {
    $attempts = 0;

    $request = function () use ($status, $headers, &$attempts): Response {
        $attempts++;

        return failoverableResponse($status, headers: $headers);
    };

    expect(fn() => $this->gateway->withErrorHandling('anthropic', $request))->toThrow(Exception::class);

    expect($attempts)->toBe(1);
})->with([
    'no header' => [429, []],
    'long delay' => [429, ['Retry-After' => '60']],
    'http date' => [429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']],
    'fractional delay' => [429, ['Retry-After' => '1.5']],
    'client error' => [400, ['Retry-After' => '1']],
]);
