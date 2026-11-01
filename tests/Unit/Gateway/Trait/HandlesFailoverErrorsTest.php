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

test('429 with an OpenAI billing error throws InsufficientCreditsException', function (array $error): void {
    expect(fn() => $this->gateway->withErrorHandling(
        'openai',
        fn(): Response => failoverableResponse(429, ['error' => $error]),
    ))->toThrow(InsufficientCreditsException::class);
})->with([
    'insufficient_quota code' => [['code' => 'insufficient_quota']],
    'insufficient_quota type' => [['type' => 'insufficient_quota']],
    'credit_balance_exhausted' => [['code' => 'credit_balance_exhausted']],
    'organization_spend_limit_exceeded' => [['code' => 'organization_spend_limit_exceeded']],
    'project_spend_limit_exceeded' => [['code' => 'project_spend_limit_exceeded']],
    'organization_usage_limit_exceeded' => [['code' => 'organization_usage_limit_exceeded']],
]);

test('429 with an OpenAI slow_down error still throws RateLimitedException', function (): void {
    expect(fn() => $this->gateway->withErrorHandling(
        'openai',
        fn(): Response => failoverableResponse(429, [
            'error' => ['code' => 'slow_down', 'type' => 'rate_limit_error'],
        ]),
    ))->toThrow(RateLimitedException::class);
});

test('billing error codes are only mapped on a 429 response', function (): void {
    expect(fn() => $this->gateway->withErrorHandling(
        'openai',
        fn(): Response => failoverableResponse(529, [
            'error' => ['code' => 'insufficient_quota'],
        ]),
    ))->toThrow(ProviderOverloadedException::class);
});

test('billing error codes are ignored when insufficientCreditCodes is overridden empty', function (): void {
    $gateway = new class
    {
        use HandlesFailoverErrorsTrait {
            withErrorHandling as public;
        }

        protected function insufficientCreditCodes(): array
        {
            return [];
        }
    };

    expect(fn(): mixed => $gateway->withErrorHandling(
        'groq',
        fn(): Response => failoverableResponse(429, [
            'error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota'],
        ]),
    ))->toThrow(RateLimitedException::class);
});

test('429 with a non-JSON body still throws RateLimitedException', function (): void {
    $response = new Response(429, ['Content-Type' => 'text/html'], '<html>Too Many Requests</html>');

    expect(fn() => $this->gateway->withErrorHandling('openai', fn(): Response => $response))
        ->toThrow(RateLimitedException::class);
});

test('429 with an insufficient_quota error is not retried', function (): void {
    $attempts = 0;

    $request = function () use (&$attempts): Response {
        $attempts++;

        return failoverableResponse(429, [
            'error' => ['code' => 'insufficient_quota'],
        ], ['Retry-After' => '1']);
    };

    expect(fn() => $this->gateway->withErrorHandling('openai', $request))->toThrow(InsufficientCreditsException::class);

    expect($attempts)->toBe(1);
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

test('non-string message is rethrown as the original RequestException', function (): void {
    expect(fn() => $this->gateway->withErrorHandling(
        'anthropic',
        fn(): Response => failoverableResponse(400, [
            'error' => ['message' => ['code' => 'invalid_prompt']],
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
