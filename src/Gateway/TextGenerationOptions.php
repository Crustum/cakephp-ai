<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use ArgumentCountError;
use Crustum\Ai\Attributes\MaxSteps;
use Crustum\Ai\Attributes\MaxTokens;
use Crustum\Ai\Attributes\Temperature;
use Crustum\Ai\Attributes\TopP;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Support\ToolChoice;
use Error;
use ReflectionClass;

/**
 * Text generation options.
 *
 * Configuration object for text generation requests, including
 * token limits, temperature, top-p, tool choice, and agent settings.
 */
class TextGenerationOptions
{
    /**
     * Constructor.
     *
     * @param int|null $maxSteps Maximum number of steps
     * @param int|null $maxTokens Maximum number of tokens
     * @param float|null $temperature Sampling temperature
     * @param \Crustum\Ai\Contracts\Agent|null $agent Agent instance
     * @param float|null $topP Top-p sampling parameter
     * @param \Crustum\Ai\Support\ToolChoice|null $toolChoice Tool choice configuration
     */
    public function __construct(
        public readonly ?int $maxSteps = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?Agent $agent = null,
        public readonly ?float $topP = null,
        public readonly ?ToolChoice $toolChoice = null,
    ) {
    }

    /**
     * Get the provider-specific options for the given provider.
     *
     * @param \Crustum\Ai\Enums\Lab|string $provider The provider
     * @return array<string, mixed>|null
     */
    public function providerOptions(Lab|string $provider): ?array
    {
        if ($this->agent instanceof HasProviderOptions) {
            return $this->agent->providerOptions(
                $provider instanceof Lab ? $provider : (Lab::tryFrom($provider) ?? $provider),
            );
        }

        return null;
    }

    /**
     * Resolve the options for the given step.
     *
     * Releases a forced tool choice after the first step so the model can answer.
     *
     * @param int $stepNumber The step number
     */
    public function forStep(int $stepNumber): self
    {
        if ($stepNumber === 0 || !$this->toolChoice instanceof ToolChoice) {
            return $this;
        }

        if (!in_array($this->toolChoice->mode, [ToolChoice::REQUIRED, ToolChoice::TOOL], true)) {
            return $this;
        }

        return new self(
            maxSteps: $this->maxSteps,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            agent: $this->agent,
            topP: $this->topP,
        );
    }

    /**
     * Create a new TextGenerationOptions instance for the given agent.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent
     */
    public static function forAgent(Agent $agent): self
    {
        $reflection = new ReflectionClass($agent);

        return new self(
            maxSteps: self::resolve($agent, $reflection, 'maxSteps', MaxSteps::class),
            maxTokens: self::resolve($agent, $reflection, 'maxTokens', MaxTokens::class),
            temperature: self::resolve($agent, $reflection, 'temperature', Temperature::class),
            agent: $agent,
            topP: self::resolve($agent, $reflection, 'topP', TopP::class),
            toolChoice: self::resolveToolChoice($agent, $reflection),
        );
    }

    /**
     * Resolve the tool choice from the agent's method, falling back to the attribute.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent
     * @param \ReflectionClass<\Crustum\Ai\Contracts\Agent> $reflection The reflection class
     */
    private static function resolveToolChoice(Agent $agent, ReflectionClass $reflection): ?ToolChoice
    {
        if (method_exists($agent, 'toolChoice')) {
            try {
                $value = $agent->toolChoice();
            } catch (ArgumentCountError | Error) {
                $value = null;
            }

            if (!is_null($value)) {
                return ToolChoice::from($value);
            }
        }

        $attributes = $reflection->getAttributes(ToolChoice::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Resolve an option from the agent's method, falling back to the attribute.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent
     * @param \ReflectionClass<\Crustum\Ai\Contracts\Agent> $reflection The reflection class
     * @param string $method Method name
     * @param class-string $attribute Attribute class name
     */
    private static function resolve(Agent $agent, ReflectionClass $reflection, string $method, string $attribute): int|float|null
    {
        if (method_exists($agent, $method)) {
            try {
                $value = $agent->{$method}();
            } catch (ArgumentCountError | Error) {
                $value = null;
            }

            if (!is_null($value)) {
                return $value;
            }
        }

        $attributes = $reflection->getAttributes($attribute);

        return $attributes === [] ? null : $attributes[0]->newInstance()->value;
    }
}
