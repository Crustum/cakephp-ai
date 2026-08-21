<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Providers;

use BadMethodCallException;
use Cake\Event\EventManagerInterface;
use Closure;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\TextGenerationLoop;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\StreamableAgentResponse;

class FakeStreamingProvider extends Provider implements TextProvider
{
    public function __construct(
        protected array $config,
        protected EventManagerInterface $events,
        protected Closure $streamFactory,
    ) {
        $this->config['name'] ??= $config['driver'] ?? 'fake-streaming';
        $this->config['driver'] ??= $this->config['name'];
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        return ($this->streamFactory)($this, $prompt);
    }

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        throw new BadMethodCallException('FakeStreamingProvider::prompt is not implemented.');
    }

    public function useTextGateway(StepTextGateway $gateway): self
    {
        return $this;
    }

    public function textGenerationLoop(): TextGenerationLoop
    {
        throw new BadMethodCallException('FakeStreamingProvider::textGenerationLoop is not implemented.');
    }

    public function defaultTextModel(): string
    {
        return 'fake-model';
    }

    public function cheapestTextModel(): string
    {
        return 'fake-model';
    }

    public function smartestTextModel(): string
    {
        return 'fake-model';
    }
}
