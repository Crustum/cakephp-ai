<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Trait\PromptableTrait;

class OllamaTopLevelOptionsAgent implements Agent, HasProviderOptions
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ($provider) {
            Lab::Ollama => [
                'format' => 'json',
                'keep_alive' => '10m',
                'logprobs' => true,
                'num_ctx' => 8192,
            ],
            default => [],
        };
    }
}
