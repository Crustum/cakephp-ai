<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi\Trait;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Utility\Value;

/**
 * Builds OpenAI Responses API text generation request bodies.
 */
trait BuildsTextRequestsTrait
{
    /**
     * Build the request body for the OpenAI Responses API.
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
            'input' => $this->mapMessagesToInput($messages, $instructions, $provider),
        ];

        return $this->mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider);
    }

    /**
     * Build the request body for a stateful Responses API continuation.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param string $continuationToken Continuation token
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @return array<string, mixed>
     */
    protected function buildContinuationBody(
        Provider $provider,
        string $model,
        string $continuationToken,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options = null,
    ): array {
        $body = [
            'model' => $model,
            'previous_response_id' => $continuationToken,
            'input' => $this->extractToolResultsInput($messages),
        ];

        return $this->mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider);
    }

    /**
     * Extract the latest tool results for a stateful continuation request.
     *
     * @param array<int, mixed> $messages Conversation messages
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolResultsInput(array $messages): array
    {
        $lastMessage = end($messages);

        if (!$lastMessage instanceof ToolResultMessage) {
            return [];
        }

        /** @var \Cake\Collection\CollectionInterface<int, array<string, mixed>> $input */
        $input = collection($lastMessage->toolResults)
            ->map(fn($toolResult): array => [
                'type' => 'function_call_output',
                'call_id' => $toolResult->resultId,
                'output' => $toolResult->text(),
            ]);

        return $input->toList();
    }

    /**
     * Merge shared Responses API options onto the given request body.
     *
     * @param array<string, mixed> $body Request body
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mergeSharedResponsesRequestOptions(
        array $body,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): array {
        if (Value::filled($tools)) {
            $mappedTools = $this->mapTools($tools, $provider, $this->isStateless($provider));

            if (Value::filled($mappedTools)) {
                $body['tool_choice'] = $options?->toolChoice instanceof ToolChoice
                    ? $this->mapToolChoice($options->toolChoice)
                    : 'auto';
                $body['tools'] = $mappedTools;
            }
        }

        if (Value::filled($schema)) {
            $body['text'] = $this->buildSchemaFormat($schema, Strict::isAppliedTo($options?->agent));
        }

        if ($options?->maxTokens !== null) {
            $body['max_output_tokens'] = $options->maxTokens;
        }

        $body = array_merge($body, array_filter([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ], static fn(mixed $value): bool => $value !== null));

        $providerOptions = $options?->providerOptions($provider->driver());

        if (Value::filled($providerOptions)) {
            $body = array_merge($body, $providerOptions);
        }

        if ($this->isStateless($provider)) {
            $body['store'] = false;
        }

        if ($this->isReasoningModel($body['model'] ?? '')) {
            $body['include'] = array_values(array_unique([
                ...($body['include'] ?? []),
                'reasoning.encrypted_content',
            ]));
        }

        return $body;
    }

    /**
     * Map a tool choice to the OpenAI Responses tool_choice shape.
     *
     * @param \Crustum\Ai\Support\ToolChoice $choice Tool choice
     * @return array<string, mixed>|string
     */
    protected function mapToolChoice(ToolChoice $choice): string|array
    {
        return match ($choice->mode) {
            ToolChoice::AUTO, ToolChoice::NONE, ToolChoice::REQUIRED => $choice->mode,
            ToolChoice::TOOL => [
                'type' => 'function',
                'name' => $choice->toolName,
            ],
        };
    }

    /**
     * Determine if OpenAI should receive full stateless conversation history.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return bool
     */
    protected function isStateless(Provider $provider): bool
    {
        return filter_var(
            $provider->additionalConfiguration()['store'] ?? true,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) === false;
    }

    /**
     * Determine if the model supports encrypted reasoning content.
     *
     * @param string $model Model name
     * @return bool
     */
    protected function isReasoningModel(string $model): bool
    {
        return (str_starts_with($model, 'gpt-5') && !str_starts_with($model, 'gpt-5-chat'))
            || str_starts_with($model, 'gpt-6')
            || str_starts_with($model, 'o4-mini')
            || str_starts_with($model, 'o3')
            || str_starts_with($model, 'o1');
    }

    /**
     * Build the text format options for structured output.
     *
     * @param array<string, mixed> $schema Structured output schema
     * @param bool $strict Whether strict schema validation is enabled
     * @return array<string, mixed>
     */
    protected function buildSchemaFormat(array $schema, bool $strict): array
    {
        $schemaArray = (new ObjectSchema($schema, strict: $strict))->toSchema();
        $schemaName = $schemaArray['name'] ?? 'schema_definition';
        $schemaBody = array_diff_key($schemaArray, ['name' => true]);

        return [
            'format' => [
                'type' => 'json_schema',
                'name' => $schemaName,
                'schema' => $schemaBody,
                'strict' => $strict,
            ],
        ];
    }
}
