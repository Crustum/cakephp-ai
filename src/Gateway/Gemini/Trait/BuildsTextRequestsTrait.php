<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Utility\Value;

/**
 * Builds Gemini generateContent request bodies.
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
        return $this->buildTextRequestBody($provider, $instructions, $messages, $tools, $schema, $options);
    }

    /**
     * Build the request body for the Gemini generateContent API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @return array<string, mixed>
     */
    protected function buildTextRequestBody(
        Provider $provider,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $contents = $this->mapMessagesToContents($messages);

        return $this->assembleRequestBody($contents, $instructions, $tools, $schema, $options, $provider);
    }

    /**
     * Assemble the Gemini request body from the given components.
     *
     * @param array<int, array<string, mixed>> $contents Message contents
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    private function assembleRequestBody(
        array $contents,
        ?string $instructions,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): array {
        $body = ['contents' => $contents];

        if (Value::filled($instructions)) {
            $body['system_instruction'] = [
                'parts' => [['text' => $instructions]],
            ];
        }

        if (Value::filled($tools)) {
            $body['tools'] = $this->mapTools($tools, $provider);

            if ($options?->toolChoice instanceof ToolChoice) {
                $body['tool_config'] = [
                    'function_calling_config' => $this->functionCallingConfig($options->toolChoice),
                ];
            }
        }

        $generationConfig = [];

        if (Value::filled($schema)) {
            $generationConfig['response_mime_type'] = 'application/json';
            $generationConfig['response_json_schema'] = $this->buildResponseSchema($schema);
        }

        if ($options?->maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $options->maxTokens;
        }

        $generationConfig = array_merge($generationConfig, array_filter([
            'temperature' => $options?->temperature,
            'topP' => $options?->topP,
        ], static fn(mixed $value): bool => $value !== null));

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        $topLevelKeys = ['cachedContent'];

        foreach ($topLevelKeys as $key) {
            if (array_key_exists($key, $providerOptions)) {
                $body[$key] = $providerOptions[$key];
                unset($providerOptions[$key]);
            }
        }

        if (Value::filled($providerOptions)) {
            $generationConfig = array_merge($generationConfig, $providerOptions);
        }

        if (Value::filled($generationConfig)) {
            $body['generationConfig'] = $generationConfig;
        }

        return $body;
    }

    /**
     * Build function response parts from tool results for the Gemini API.
     *
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Tool results
     * @return array<int, array<string, mixed>>
     */
    protected function buildFunctionResponseParts(array $toolResults): array
    {
        return array_values(array_map(function (ToolResult $result): array {
            $functionResponse = [
                'name' => $result->name,
                'response' => [
                    'name' => $result->name,
                    'content' => $this->serializeToolResultOutput($result->result),
                ],
                'id' => $result->id,
            ];

            return ['functionResponse' => $functionResponse];
        }, $toolResults));
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
     * Map a tool choice to the Gemini function_calling_config block.
     *
     * @param \Crustum\Ai\Support\ToolChoice $choice Tool choice
     * @return array<string, mixed>
     */
    protected function functionCallingConfig(ToolChoice $choice): array
    {
        return match ($choice->mode) {
            ToolChoice::AUTO => ['mode' => 'AUTO'],
            ToolChoice::NONE => ['mode' => 'NONE'],
            ToolChoice::REQUIRED => ['mode' => 'ANY'],
            ToolChoice::TOOL => [
                'mode' => 'ANY',
                'allowed_function_names' => [$choice->toolName],
            ],
        };
    }
}
