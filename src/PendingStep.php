<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Tools\ToolNameResolver;

/**
 * A generation step handed to agent middleware before the model is called.
 */
class PendingStep
{
    /**
     * Constructor.
     *
     * @param int $number Step index within the run
     * @param bool $isFinalStep Whether this is the final step
     * @param string $provider Provider name
     * @param string $model Model identifier
     * @param string|null $instructions System instructions
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Messages being sent for this step
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param array<string, mixed>|null $schema Response schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param array<int, \Crustum\Ai\Responses\Data\Step> $steps Steps completed so far in this run
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage Usage accumulated by the completed steps
     * @param int|null $timeout Timeout in seconds
     * @param string|null $invocationId Run invocation identifier
     */
    public function __construct(
        public readonly int $number,
        public readonly bool $isFinalStep,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $instructions,
        public readonly array $messages,
        public readonly array $tools,
        public readonly ?array $schema,
        public readonly ?TextGenerationOptions $options,
        public readonly array $steps = [],
        public readonly TextUsage $usage = new TextUsage(),
        public readonly ?int $timeout = null,
        public readonly ?string $invocationId = null,
    ) {
    }

    /**
     * Determine whether this is the first generation step.
     *
     * @return bool
     */
    public function isFirstStep(): bool
    {
        return $this->number === 0;
    }

    /**
     * Create a copy using a different model.
     *
     * @param string $model Model identifier
     */
    public function withModel(string $model): self
    {
        return $this->with(['model' => $model]);
    }

    /**
     * Create a copy using different instructions.
     *
     * @param string|null $instructions System instructions
     */
    public function withInstructions(?string $instructions): self
    {
        return $this->with(['instructions' => $instructions]);
    }

    /**
     * Replace the history sent for this step only; the run's history still grows from the original.
     *
     * @param iterable<int, \Crustum\Ai\Messages\Message> $messages Messages
     */
    public function withMessages(iterable $messages): self
    {
        $list = [];

        foreach ($messages as $message) {
            $list[] = $message;
        }

        return $this->with(['messages' => $list]);
    }

    /**
     * Create a copy using the given tools.
     *
     * @param iterable<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Tools
     */
    public function withTools(iterable $tools): self
    {
        $list = [];

        foreach ($tools as $tool) {
            $list[] = $tool;
        }

        return $this->with(['tools' => $list]);
    }

    /**
     * Create a copy containing only the named tools.
     *
     * @param string ...$names Tool names
     */
    public function onlyTools(string ...$names): self
    {
        return $this->withTools(array_filter(
            $this->tools,
            fn(mixed $tool): bool => in_array(ToolNameResolver::resolve($tool), $names, true),
        ));
    }

    /**
     * Create a copy excluding the named tools.
     *
     * @param string ...$names Tool names
     */
    public function withoutTools(string ...$names): self
    {
        return $this->withTools(array_filter(
            $this->tools,
            fn(mixed $tool): bool => !in_array(ToolNameResolver::resolve($tool), $names, true),
        ));
    }

    /**
     * Create a copy using a different tool choice.
     *
     * @param \Crustum\Ai\Support\ToolChoice|array<string, mixed>|string|null $toolChoice Tool choice
     */
    public function withToolChoice(ToolChoice|string|array|null $toolChoice): self
    {
        return $this->withOptions($this->resolvedOptions()->withToolChoice(
            $toolChoice === null ? null : ToolChoice::from($toolChoice),
        ));
    }

    /**
     * Create a copy using a different maximum token count.
     *
     * @param int|null $maxTokens Maximum number of tokens
     */
    public function withMaxTokens(?int $maxTokens): self
    {
        return $this->withOptions($this->resolvedOptions()->withMaxTokens($maxTokens));
    }

    /**
     * Create a copy using the given provider options.
     *
     * @param array<string, mixed> $providerOptions Provider options
     */
    public function withProviderOptions(array $providerOptions): self
    {
        $resolved = $this->resolvedOptions();

        return $this->withOptions($resolved->withProviderOptions(
            [...($resolved->providerOptions ?? []), ...$providerOptions],
        ));
    }

    /**
     * Create a copy using the given options.
     *
     * @param \Crustum\Ai\Gateway\TextGenerationOptions $options Generation options
     */
    protected function withOptions(TextGenerationOptions $options): self
    {
        return $this->with(['options' => $options]);
    }

    /**
     * Get the step options, creating an empty set when none were provided.
     *
     * @return \Crustum\Ai\Gateway\TextGenerationOptions
     */
    protected function resolvedOptions(): TextGenerationOptions
    {
        return $this->options ?? new TextGenerationOptions();
    }

    /**
     * Create a copy with the given property overrides.
     *
     * @param array<string, mixed> $overrides Property overrides
     */
    protected function with(array $overrides): self
    {
        return new self(...[...get_object_vars($this), ...$overrides]);
    }
}
