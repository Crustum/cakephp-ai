<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Anthropic\Trait;

use Crustum\Ai\Attributes\CacheConversation;
use Crustum\Ai\Attributes\CacheInstructions;
use Crustum\Ai\Attributes\CacheToolDefinitions;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Utility\Value;
use InvalidArgumentException;

/**
 * Builds Anthropic Messages API text generation request bodies.
 */
trait BuildsTextRequestsTrait
{
    /**
     * Build the request body for the Anthropic Messages API.
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
            'messages' => $this->mapMessages($messages),
            'max_tokens' => $options->maxTokens ?? 64000,
        ];

        if (Value::filled($instructions)) {
            $body['system'] = $instructions;
        }

        $mappedTools = Value::filled($tools) ? $this->mapTools($tools, $provider) : [];

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        if (Value::filled($schema) && $this->supportsNativeStructuredOutput($provider)) {
            $body['output_config'] = [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => AnthropicSchemaSanitizer::sanitize(
                        (new ObjectSchema($schema))->toSchema(),
                    ),
                ],
            ];

            if (Value::filled($mappedTools)) {
                $body['tools'] = $mappedTools;
                $body['tool_choice'] = ['type' => 'auto'];
            }
        } else {
            if (Value::filled($schema)) {
                $mappedTools[] = $this->buildStructuredOutputTool($schema);
            }

            if (Value::filled($mappedTools)) {
                $body['tools'] = $mappedTools;
                $body['tool_choice'] = $this->resolveToolChoice($schema, $tools, $providerOptions, $options?->toolChoice);
            }
        }

        $body = array_merge($body, array_filter([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ], static fn(mixed $value): bool => $value !== null));

        return $this->applyPromptCacheBreakpoints(array_merge($body, $providerOptions), $options);
    }

    /**
     * Stamp the requested cache breakpoints onto the final request body.
     *
     * @param array<string, mixed> $body Request body
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @return array<string, mixed>
     */
    protected function applyPromptCacheBreakpoints(array $body, ?TextGenerationOptions $options): array
    {
        $this->ensureValidPromptCacheOrder($body, $options);

        if (isset($body['system']) && $options?->cacheInstructions instanceof CacheInstructions) {
            $system = is_string($body['system'])
                ? [['type' => 'text', 'text' => $body['system']]]
                : $body['system'];

            $system[array_key_last($system)]['cache_control'] = $this->cacheControl($options->cacheInstructions->ttl);

            $body['system'] = $system;
        }

        if (isset($body['tools']) && $options?->cacheToolDefinitions instanceof CacheToolDefinitions) {
            $body['tools'][array_key_last($body['tools'])]['cache_control'] = $this->cacheControl($options->cacheToolDefinitions->ttl);
        }

        if (isset($body['messages']) && $options?->cacheConversation instanceof CacheConversation) {
            $this->stampConversationCacheBreakpoint($body['messages'], $this->cacheControl($options->cacheConversation->ttl));
        }

        return $body;
    }

    /**
     * Stamp a prompt-cache breakpoint on the final content block of the last non-empty message.
     *
     * @param array<int, array<string, mixed>> $messages Mapped conversation messages
     * @param array<string, string> $cacheControl Cache control block
     * @return void
     */
    protected function stampConversationCacheBreakpoint(array &$messages, array $cacheControl): void
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $content = $messages[$i]['content'] ?? null;

            if (in_array($content, [null, '', []], true)) {
                continue;
            }

            if (is_string($content)) {
                $content = [['type' => 'text', 'text' => $content]];
            }

            if (!is_array($content)) {
                continue;
            }

            $content[array_key_last($content)]['cache_control'] = $cacheControl;
            $messages[$i]['content'] = $content;

            return;
        }
    }

    /**
     * Ensure longer-lived cache breakpoints precede shorter-lived breakpoints.
     *
     * @param array<string, mixed> $body Request body
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @throws \InvalidArgumentException When cache TTL ordering is invalid
     */
    protected function ensureValidPromptCacheOrder(array $body, ?TextGenerationOptions $options): void
    {
        if (
            $options?->cacheInstructions?->ttl === '1h'
            && $options->cacheToolDefinitions instanceof CacheToolDefinitions
            && $options->cacheToolDefinitions->ttl !== '1h'
        ) {
            throw new InvalidArgumentException('A one-hour instructions cache requires the tool definitions cache to also use a one-hour TTL.');
        }

        if (
            ($body['cache_control']['ttl'] ?? null) === '1h'
            && (($options?->cacheInstructions instanceof CacheInstructions && $options->cacheInstructions->ttl !== '1h')
                || ($options?->cacheToolDefinitions instanceof CacheToolDefinitions && $options->cacheToolDefinitions->ttl !== '1h')
                || ($options?->cacheConversation instanceof CacheConversation && $options->cacheConversation->ttl !== '1h'))
        ) {
            throw new InvalidArgumentException('A one-hour automatic cache requires all explicit cache breakpoints to also use a one-hour TTL.');
        }
    }

    /**
     * Build the cache control block for the given TTL.
     *
     * @param string|null $ttl Cache TTL
     * @return array<string, string>
     */
    protected function cacheControl(?string $ttl): array
    {
        return array_filter(['type' => 'ephemeral', 'ttl' => $ttl]);
    }

    /**
     * Determine the tool_choice strategy for the request.
     *
     * @param array<string, mixed>|null $schema Structured output schema
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed> $providerOptions Provider options
     * @param \Crustum\Ai\Support\ToolChoice|null $toolChoice Tool choice
     * @return array<string, mixed>
     */
    protected function resolveToolChoice(?array $schema, array $tools, array $providerOptions, ?ToolChoice $toolChoice = null): array
    {
        $thinking = isset($providerOptions['thinking']);

        if (Value::filled($schema)) {
            if ($thinking) {
                return ['type' => 'auto'];
            }

            return Value::filled($tools)
                ? ['type' => 'any']
                : ['type' => 'tool', 'name' => 'output_structured_data'];
        }

        if (!$toolChoice instanceof ToolChoice) {
            return ['type' => 'auto'];
        }

        if ($thinking && in_array($toolChoice->mode, [ToolChoice::REQUIRED, ToolChoice::TOOL], true)) {
            throw new InvalidArgumentException(
                'Anthropic cannot force tool use while extended thinking is enabled. Use ToolChoice::auto or ToolChoice::none, or disable thinking.',
            );
        }

        return match ($toolChoice->mode) {
            ToolChoice::AUTO => ['type' => 'auto'],
            ToolChoice::NONE => ['type' => 'none'],
            ToolChoice::REQUIRED => ['type' => 'any'],
            ToolChoice::TOOL => ['type' => 'tool', 'name' => $toolChoice->toolName],
        };
    }

    /**
     * Determine if the provider supports native structured output via output_config.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return bool
     */
    protected function supportsNativeStructuredOutput(Provider $provider): bool
    {
        $config = $provider->additionalConfiguration();

        if (array_key_exists('use_native_structured_output', $config)) {
            return (bool)$config['use_native_structured_output'];
        }

        return true;
    }

    /**
     * Build the synthetic tool definition for structured output.
     *
     * @param array<string, mixed> $schema Structured output schema
     * @return array<string, mixed>
     */
    protected function buildStructuredOutputTool(array $schema): array
    {
        $schemaArray = (new ObjectSchema($schema))->toSchema();

        return [
            'name' => 'output_structured_data',
            'description' => 'Output the structured data matching the required schema.',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object)($schemaArray['properties'] ?? []),
                'required' => $schemaArray['required'] ?? [],
            ],
        ];
    }
}
