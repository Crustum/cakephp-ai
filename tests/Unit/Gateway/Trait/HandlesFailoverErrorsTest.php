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

function failoverableResponse(int $status, array $body = []): Response
{
    return new Response(
        $status,
        ['Content-Type' => 'application/json'],
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
