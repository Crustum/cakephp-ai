<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;

class PromptCacheAgent implements Agent, HasProviderOptions, HasTools
{
    use PromptableTrait;

    public function __construct(
        protected bool $withTools = true,
        protected array $options = [],
    ) {
    }

    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    public function tools(): iterable
    {
        return $this->withTools ? [new RandomNumberGenerator()] : [];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->options;
    }
}
