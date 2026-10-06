<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Cake\Utility\Inflector;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Utility\Value;

/**
 * Builds Gemini Interactions API request bodies.
 */
trait BuildsTextRequestsTrait
{
    /**
     * The request keys Gemini expects beside the generation config rather than within it.
     *
     * @var array<int, string>
     */
    private const TOP_LEVEL_INTERACTION_KEYS = [
        'agent', 'agent_config', 'background', 'environment', 'labels',
        'previous_interaction_id', 'response_format', 'safety_settings',
        'service_tier', 'store', 'user_metadata', 'webhook_config',
    ];

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
     * Build the request body for the Gemini Interactions API.
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
        $input = $this->mapMessagesToInput($messages);

        return $this->assembleRequestBody($model, $input, $instructions, $tools, $schema, $options, $provider);
    }

    /**
     * Assemble the Gemini request body from the given components.
     *
     * @param string $model Model name
     * @param array<int, array<string, mixed>> $input Interaction input steps
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    private function assembleRequestBody(
        string $model,
        array $input,
        ?string $instructions,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): array {
        $body = [
            'model' => $model,
            'input' => $input,
            // Conversation history is replayed from our own store, so Gemini need not retain it.
            'store' => false,
        ];

        if (Value::filled($instructions)) {
            $body['system_instruction'] = $instructions;
        }

        if (Value::filled($tools)) {
            $body['tools'] = $this->mapTools($tools, $provider);
        }

        if (Value::filled($schema)) {
            $body['response_format'] = [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $this->buildResponseSchema($schema),
            ];
        }

        $generationConfig = array_filter([
            'max_output_tokens' => $options?->maxTokens,
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ], static fn(mixed $value): bool => $value !== null);

        if ($options?->toolChoice instanceof ToolChoice) {
            $generationConfig['tool_choice'] = $this->toolChoiceConfig($options->toolChoice);
        }

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        foreach (['generation_config', 'generationConfig'] as $key) {
            if (is_array($providerOptions[$key] ?? null)) {
                $generationConfigOption = $providerOptions[$key];
                unset($providerOptions[$key]);
                $providerOptions = array_merge($providerOptions, $generationConfigOption);
            }
        }

        foreach ($providerOptions as $key => $value) {
            $snakeKey = Inflector::underscore((string)$key);

            if (in_array($snakeKey, self::TOP_LEVEL_INTERACTION_KEYS, true)) {
                $body[$snakeKey] = $value;
                unset($providerOptions[$key]);
            }
        }

        if (Value::filled($providerOptions)) {
            $generationConfig = array_merge($generationConfig, $providerOptions);
        }

        if (Value::filled($generationConfig)) {
            $body['generation_config'] = $generationConfig;
        }

        return $body;
    }

    /**
     * Build function result steps from tool results for the Gemini API.
     *
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Tool results
     * @return array<int, array<string, mixed>>
     */
    protected function buildFunctionResultSteps(array $toolResults): array
    {
        // ToolResult::$id is always a string in Cake, so every step carries its call id.
        return array_values(array_map(fn(ToolResult $result): array => [
            'type' => 'function_result',
            'name' => $result->name,
            'call_id' => $result->id,
            'result' => [['type' => 'text', 'text' => $result->text()]],
        ], $toolResults));
    }

    /**
     * Build the response schema for structured output.
     *
     * @param array<string, mixed> $schema Structured output schema
     * @return array<string, mixed>
     */
    protected function buildResponseSchema(array $schema): array
    {
        return (new ObjectSchema($schema))->toSchema();
    }

    /**
     * Map a tool choice to the Gemini tool choice configuration.
     *
     * @param \Crustum\Ai\Support\ToolChoice $choice Tool choice
     * @return array<string, mixed>|string
     */
    protected function toolChoiceConfig(ToolChoice $choice): string|array
    {
        return match ($choice->mode) {
            ToolChoice::AUTO => 'auto',
            ToolChoice::NONE => 'none',
            ToolChoice::REQUIRED => 'any',
            ToolChoice::TOOL => [
                'allowed_tools' => [
                    'mode' => 'any',
                    'tools' => [$choice->toolName],
                ],
            ],
        };
    }
}
