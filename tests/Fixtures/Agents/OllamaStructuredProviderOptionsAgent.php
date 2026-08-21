<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Contracts\HasStructuredOutput;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Trait\PromptableTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;

#[Strict]
class OllamaStructuredProviderOptionsAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use PromptableTrait;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output and knows about periodic table element symbols.';
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema->string()->required(),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ($provider) {
            Lab::Ollama => [
                'format' => 'json',
            ],
            default => [],
        };
    }
}
