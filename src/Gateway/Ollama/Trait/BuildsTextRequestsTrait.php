<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Ollama\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Gateway\Trait\ComposesSchemaInstructionsTrait;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Utility\Value;

/**
 * Builds Ollama Chat API text generation request bodies.
 */
trait BuildsTextRequestsTrait
{
    use ComposesSchemaInstructionsTrait;

    /**
     * Build the request body for the current text generation step.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Step context
     * @return array<string, mixed>
     */
    protected function buildStepBody(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        return $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
    }

    /**
     * Build the request body for the Ollama Chat API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @return array<string, mixed>
     */
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        return $this->buildChatRequestBody(
            $provider,
            $model,
            $this->mapMessagesToChat($messages, $this->composeInstructions($instructions, $schema)),
            $tools,
            $schema,
            $options,
        );
    }

    /**
     * Build a request body from pre-mapped chat messages.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param array<int, array<string, mixed>> $chatMessages Mapped chat messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param bool $stream Whether to stream
     * @return array<string, mixed>
     */
    protected function buildChatRequestBody(
        Provider $provider,
        string $model,
        array $chatMessages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        bool $stream = false,
    ): array {
        $body = [
            'model' => $model,
            'messages' => $chatMessages,
            'stream' => $stream,
        ];

        if (Value::filled($tools)) {
            $mappedTools = $this->mapTools($tools);

            if (Value::filled($mappedTools)) {
                $body['tools'] = $mappedTools;
            }
        }

        if (Value::filled($schema)) {
            $body['format'] = $this->buildResponseFormat($schema);
        }

        $ollamaOptions = array_filter([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
            'num_predict' => $options?->maxTokens,
        ], static fn(mixed $value): bool => $value !== null);

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        $topLevelKeys = ['format', 'keep_alive', 'think', 'logprobs', 'top_logprobs'];

        foreach ($topLevelKeys as $key) {
            if (array_key_exists($key, $providerOptions)) {
                $body[$key] ??= $providerOptions[$key];
                unset($providerOptions[$key]);
            }
        }

        $mergedOptions = array_merge($ollamaOptions, $providerOptions);

        if (Value::filled($mergedOptions)) {
            $body['options'] = $mergedOptions;
        }

        return $body;
    }

    /**
     * Build the response format schema for structured output.
     *
     * @param array<string, mixed> $schema Structured output schema
     * @return array<string, mixed>
     */
    protected function buildResponseFormat(array $schema): array
    {
        return (new ObjectSchema($schema))->toSchema();
    }
}
