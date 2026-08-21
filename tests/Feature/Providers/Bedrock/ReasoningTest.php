<?php
declare(strict_types=1);

use Crustum\Ai\Gateway\TextGenerationLoop;
use Crustum\Ai\Messages\AssistantMessage;

    test('captures reasoning content into providerContentBlocks', function (): void {
        $client = $this->fakeBedrockConverse([
            'output' => [
                'message' => [
                    'content' => [
                        ['reasoningContent' => ['reasoningText' => ['text' => 'thinking...', 'signature' => 'sig-1']]],
                        ['text' => 'Hello'],
                    ],
                ],
            ],
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
            'stopReason' => 'end_turn',
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
        );

        $assistant = $response->messages->first(fn($m): bool => $m instanceof AssistantMessage);

        expect($assistant->providerContentBlocks)->toEqual([
            ['reasoningContent' => ['reasoningText' => ['text' => 'thinking...', 'signature' => 'sig-1']]],
            ['text' => 'Hello'],
        ]);
        expect($assistant->content)->toBe('Hello');
    });
