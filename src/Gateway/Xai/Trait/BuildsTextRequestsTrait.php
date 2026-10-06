<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Xai\Trait;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Utility\Value;

/**
 * Builds xAI Responses API text generation request bodies.
 */
trait BuildsTextRequestsTrait
{
    /**
     * Build the request body for the xAI Responses API.
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
        $input = $this->mapMessagesToInput($messages, $instructions);

        $body = ['model' => $model, 'input' => $input];

        return $this->mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider);
    }

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
        return $stepContext->continuationToken
            ? $this->buildContinuationBody($stepContext->continuationToken, $model, $messages, $tools, $provider, $schema, $options)
            : $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
    }

    /**
     * Build a lightweight continuation body using previous_response_id.
     *
     * @param string $previousResponseId Previous response identifier
     * @param string $model Model name
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @return array<string, mixed>
     */
    protected function buildContinuationBody(
        string $previousResponseId,
        string $model,
        array $messages,
        array $tools,
        Provider $provider,
        ?array $schema,
        ?TextGenerationOptions $options = null,
    ): array {
        $body = [
            'model' => $model,
            'previous_response_id' => $previousResponseId,
            'input' => $this->extractToolResultsInput($messages),
        ];

        return $this->mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider);
    }

    /**
     * Apply options shared by full requests and previous_response_id continuations.
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
            $body['tool_choice'] = $options?->toolChoice instanceof ToolChoice
                ? $this->mapToolChoice($options->toolChoice)
                : 'auto';
            $body['tools'] = $this->mapTools($tools, $provider);
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
            return array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Map a tool choice to the xAI Responses tool_choice shape.
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
     * Extract tool result items from the trailing ToolResultMessage for a continuation request.
     *
     * @param array<int, mixed> $messages Conversation messages
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolResultsInput(array $messages): array
    {
        $input = [];

        $lastMessage = end($messages);

        if ($lastMessage instanceof ToolResultMessage) {
            foreach ($lastMessage->toolResults as $toolResult) {
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => $toolResult->resultId,
                    'output' => $toolResult->text(),
                ];
            }
        }

        return $input;
    }

    /**
     * Build the text format options for structured output.
     *
     * @param array<string, mixed> $schema Structured output schema
     * @return array<string, mixed>
     */
    protected function buildSchemaFormat(array $schema, bool $strict): array
    {
        $objectSchema = new ObjectSchema($schema, strict: $strict);

        $schemaArray = $objectSchema->toSchema();

        return [
            'format' => [
                'type' => 'json_schema',
                'name' => $schemaArray['name'] ?? 'schema_definition',
                'schema' => array_diff_key($schemaArray, ['name' => true]),
                'strict' => $strict,
            ],
        ];
    }
}
