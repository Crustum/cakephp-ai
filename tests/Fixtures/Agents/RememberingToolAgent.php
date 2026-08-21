<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;
use Crustum\Ai\Trait\RemembersConversationsTrait;

class RememberingToolAgent implements Agent, HasProviderOptions, HasTools
{
    use PromptableTrait;
    use RemembersConversationsTrait;

    public function __construct(public array $extraProviderOptions = [])
    {
    }

    public function instructions(): string
    {
        return 'Call the FixedNumberGenerator tool before answering. Answer with one short sentence.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator()];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->extraProviderOptions;
    }
}
