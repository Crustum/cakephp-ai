<?php
declare(strict_types=1);

use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;

test('configured headers override defaults case insensitively', function (): void {
    $merger = new class
    {
        use MergesHeadersTrait;

        public function merge(array $headers, array $configuredHeaders): array
        {
            return $this->mergeConfiguredHeaders($headers, $configuredHeaders);
        }
    };

    $headers = $merger->merge(
        ['Authorization' => 'Bearer provider-key', 'Content-Type' => 'application/json'],
        [
            'authorization' => 'Bearer proxy-token',
            'X-Session-Affinity' => 'discarded',
            'x-session-affinity' => 'abc-123',
        ],
    );

    expect($headers)->toBe([
        'Authorization' => 'Bearer proxy-token',
        'Content-Type' => 'application/json',
        'X-Session-Affinity' => 'abc-123',
    ]);
});
