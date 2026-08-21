<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenRouter\Trait;

use Cake\Utility\Hash;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Utility\Value;

/**
 * Builds OpenRouter text generation request bodies.
 */
trait BuildsTextRequestsTrait
{
    /**
     * Build the request body for the current text generation step.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
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
        Provider $provider,
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
     * Build the request body for the Chat Completions API.
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
        $body = [
            'model' => $model,
            'messages' => $this->mapMessagesToChat($messages, $instructions),
        ];

        if (Value::filled($tools)) {
            $mappedTools = $this->mapTools($tools, $provider);

            if (Value::filled($mappedTools)) {
                $body['tool_choice'] = $options?->toolChoice instanceof ToolChoice
                    ? $this->mapToolChoice($options->toolChoice)
                    : 'auto';
                $body['tools'] = $mappedTools;
            }
        }

        if (Value::filled($schema)) {
            $body['response_format'] = $this->buildResponseFormat($schema);
        }

        if ($options?->maxTokens !== null) {
            $body['max_tokens'] = $options->maxTokens;
        }

        $body = array_merge($body, array_filter([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ], static fn(mixed $value): bool => $value !== null));

        $providerOptions = $options?->providerOptions($provider->driver());

        if (Value::filled($providerOptions)) {
            return array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Build the response format options for structured output.
     *
     * @param array<string, mixed> $schema Structured output schema
     * @return array<string, mixed>
     */
    protected function buildResponseFormat(array $schema): array
    {
        $objectSchema = new ObjectSchema($schema);

        $schemaArray = $objectSchema->toSchema();

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaArray['name'] ?? 'schema_definition',
                'schema' => Hash::remove($schemaArray, 'name'),
                'strict' => true,
            ],
        ];
    }
}
