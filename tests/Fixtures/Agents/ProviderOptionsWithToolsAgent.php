<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;

class ProviderOptionsWithToolsAgent implements Agent, HasProviderOptions, HasTools
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    public function tools(): iterable
    {
        return [
            new FixedNumberGenerator(),
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
            Lab::Azure => [
                'frequency_penalty' => 0.5,
            ],
            Lab::OpenAI => [
                'reasoning' => [
                    'effort' => 'high',
                ],
                'frequency_penalty' => 0.5,
            ],
            Lab::xAI => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Groq => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Mistral => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Ollama => [
                'top_k' => 40,
            ],
            Lab::OpenRouter => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Gemini => [
                'thinkingConfig' => [
                    'thinkingBudget' => 10000,
                ],
            ],
            Lab::DeepSeek => [
                'frequency_penalty' => 0.5,
            ],
            default => [],
        };
    }
}
