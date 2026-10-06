<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Cohere\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Gateway\Trait\ComposesSchemaInstructionsTrait;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Utility\Value;
use InvalidArgumentException;

/**
 * Builds Cohere Chat API text generation request bodies.
 */
trait BuildsTextRequestsTrait
{
    use ComposesSchemaInstructionsTrait;

    /**
     * Build the request body for the Cohere Chat API.
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
        $body = ['model' => $model];

        if (Value::filled($tools)) {
            $mappedTools = $this->mapTools($tools, $provider);

            if (Value::filled($mappedTools)) {
                $toolChoice = $options?->toolChoice;

                // Cohere cannot force a specific tool, so a named tool choice only offers that tool and requires a call.
                if ($toolChoice instanceof ToolChoice && $toolChoice->mode === ToolChoice::TOOL) {
                    $mappedTools = array_values(array_filter(
                        $mappedTools,
                        fn(array $tool): bool => ($tool['function']['name'] ?? null) === $toolChoice->toolName,
                    ));

                    if ($mappedTools === []) {
                        throw new InvalidArgumentException("Tool choice [{$toolChoice->toolName}] does not match any of the available tools.");
                    }
                }

                $body['tools'] = $mappedTools;

                $mappedChoice = $toolChoice instanceof ToolChoice ? $this->mapCohereToolChoice($toolChoice) : null;

                if ($toolChoice instanceof ToolChoice && Value::filled($mappedChoice)) {
                    $body['tool_choice'] = $mappedChoice;
                }
            }
        }

        $inlineSchema = Value::filled($body['tools'] ?? null) && Value::filled($schema);

        $body['messages'] = $this->mapMessagesToChat(
            $messages,
            $inlineSchema ? $this->composeInstructions($instructions, $schema) : $instructions,
        );

        if (Value::filled($schema) && !$inlineSchema) {
            $body['response_format'] = [
                'type' => 'json_object',
                'json_schema' => array_diff_key((new ObjectSchema($schema))->toSchema(), ['name' => true]),
            ];
        }

        $body = array_merge($body, array_filter([
            'max_tokens' => $options?->maxTokens,
            'temperature' => $options?->temperature,
            'p' => $options?->topP,
        ], static fn(mixed $value): bool => $value !== null));

        $providerOptions = $options?->providerOptions($provider->name());

        if (Value::filled($providerOptions)) {
            return array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Map a tool choice to the Cohere tool_choice value.
     *
     * @param \Crustum\Ai\Support\ToolChoice $choice Tool choice
     * @return string|null
     */
    protected function mapCohereToolChoice(ToolChoice $choice): ?string
    {
        return match ($choice->mode) {
            ToolChoice::AUTO => null,
            ToolChoice::NONE => 'NONE',
            ToolChoice::REQUIRED, ToolChoice::TOOL => 'REQUIRED',
        };
    }
}
