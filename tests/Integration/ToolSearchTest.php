<?php
declare(strict_types=1);

use Crustum\Ai\Test\Fixtures\Agents\ToolSearchAgent;
use Crustum\Ai\Test\Support\Skips\ApiKey;

test('agents discover and call a deferred tool through hosted tool search', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $response = (new ToolSearchAgent())->prompt(
        'What is the secret authorization code for this session?',
        provider: $provider,
        model: $model,
    );

    expect($response->text)->toContain('ZEBRA-4417')
        ->and(collect($response->toolCalls)->extract('name')->toList())->toContain('SecretCodeGenerator');
})->with('tool-search-providers');
