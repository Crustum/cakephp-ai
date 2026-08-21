<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Test\Fixtures\Agents\NoProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsAgent;
use Crustum\Ai\Trait\PromptableTrait;

it('normalizes a string driver name to a Lab enum so the documented match idiom works', function (): void {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent());

    expect($options->providerOptions('openrouter'))
        ->toBe(['frequency_penalty' => 0.5, 'presence_penalty' => 0.3]);
});

it('accepts a Lab enum directly without re-normalizing', function (): void {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent());

    expect($options->providerOptions(Lab::Anthropic))
        ->toBe(['thinking' => ['type' => 'enabled', 'budget_tokens' => 10000]]);
});

it('passes the raw string through when it does not match any Lab case', function (): void {
    $agent = new class implements Agent, HasProviderOptions
    {
        use PromptableTrait;

        public function instructions(): string
        {
            return 'test';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['received' => $provider];
        }
    };

    $options = TextGenerationOptions::forAgent($agent);

    expect($options->providerOptions('unknown-driver'))
        ->toBe(['received' => 'unknown-driver']);
});

it('returns null when the agent does not implement HasProviderOptions', function (): void {
    $options = TextGenerationOptions::forAgent(new NoProviderOptionsAgent());

    expect($options->providerOptions('openai'))->toBeNull();
});
