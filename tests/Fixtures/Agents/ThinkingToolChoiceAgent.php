<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;

#[ToolChoice(ToolChoice::REQUIRED)]
class ThinkingToolChoiceAgent implements Agent, HasProviderOptions, HasTools
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [
            new RandomNumberGenerator(),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return [
            'thinking' => [
                'type' => 'enabled',
                'budget_tokens' => 10_000,
            ],
        ];
    }
}
