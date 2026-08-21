<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Contracts\HasStructuredOutput;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Trait\PromptableTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;

class StructuredWithThinkingAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'age' => $schema->integer()->required(),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ($provider) {
            Lab::Anthropic => [
                'thinking' => [
                    'type' => 'enabled',
                    'budget_tokens' => 10000,
                ],
            ],
            default => [],
        };
    }
}
