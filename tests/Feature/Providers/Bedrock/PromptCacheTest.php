<?php
declare(strict_types=1);

use Crustum\Ai\Attributes\CacheInstructions;
use Crustum\Ai\Attributes\CacheToolDefinitions;
use Crustum\Ai\Gateway\TextGenerationLoop;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Test\Fixtures\Agents\PromptCacheAgent;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;

test('prompt cache attribute is ignored for Bedrock (no cache_control in body)', function (): void {
    $client = $this->fakeBedrockConverse([
        'output' => [
            'message' => [
                'role' => 'assistant',
                'content' => [
                    ['text' => 'Hello'],
                ],
            ],
        ],
        'usage' => [
            'inputTokens' => 10,
            'outputTokens' => 5,
        ],
        'stopReason' => 'end_turn',
    ]);

    $gateway = $this->gatewayWithClient($client);

    $response = (new TextGenerationLoop($gateway))->generate(
        $this->bedrockProvider(),
        'us.anthropic.claude-sonnet-4-5-20250929-v1:0',
        'Hi',
    );

    expect($response->text)->toBe('Hello');
});

test('cache instructions attribute appends a cache point', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions] class extends PromptCacheAgent {
        }),
    );

    expect($parameters['system'])->toBe([
        ['text' => 'You are a helpful assistant.'],
        ['cachePoint' => ['type' => 'default']],
    ]);
});

test('cache tool definitions attribute appends a cache point to the tool config', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheToolDefinitions] class extends PromptCacheAgent {
        }),
        [new RandomNumberGenerator()],
    );

    expect($parameters['system'])->toBe([['text' => 'You are a helpful assistant.']])
        ->and(end($parameters['toolConfig']['tools']))->toBe(['cachePoint' => ['type' => 'default']]);
});

test('an agent without cache attributes adds no cache points', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new PromptCacheAgent()),
        [new RandomNumberGenerator()],
    );

    expect($parameters['system'])->toBe([['text' => 'You are a helpful assistant.']])
        ->and($parameters['toolConfig']['tools'])->each->toHaveKey('toolSpec');
});

test('a requested ttl is added to the bedrock cache point', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions('1h')] class extends PromptCacheAgent {
        }),
    );

    expect($parameters['system'])->toBe([
        ['text' => 'You are a helpful assistant.'],
        ['cachePoint' => ['type' => 'default', 'ttl' => '1h']],
    ]);
});

test('a longer instructions ttl requires the tools cache to use the same ttl', function (): void {
    $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions('1h')] #[CacheToolDefinitions('5m')] class extends PromptCacheAgent {
        }),
        [new RandomNumberGenerator()],
    );
})->throws(InvalidArgumentException::class);

test('cache points survive provider options that override the same keys', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheInstructions] class (options: ['system' => [['text' => 'Overridden instructions.']]]) extends PromptCacheAgent {
        }),
    );

    expect($parameters['system'])->toBe([
        ['text' => 'Overridden instructions.'],
        ['cachePoint' => ['type' => 'default']],
    ]);
});

test('the tools target is a no-op when the request carries no tool config', function (): void {
    $parameters = $this->capturedConverseParameters(
        TextGenerationOptions::forAgent(new #[CacheToolDefinitions] class (withTools: false) extends PromptCacheAgent {
        }),
    );

    expect($parameters)->not->toHaveKey('toolConfig');
});
