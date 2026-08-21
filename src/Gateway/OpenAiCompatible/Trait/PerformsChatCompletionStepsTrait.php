<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAiCompatible\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Utility\Value;
use Generator;

/**
 * Performs OpenAI Chat Completions text generation steps.
 */
trait PerformsChatCompletionStepsTrait
{
    /**
     * Generate text for a single Chat Completions step.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param int|null $timeout Timeout in seconds
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Step context
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn() => $this->client($provider, $timeout)->post('chat/completions', $body),
        );

        $data = $response->getJson() ?? [];

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, Value::filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single Chat Completions step.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param int|null $timeout Timeout in seconds
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Step context
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent, mixed, \Crustum\Ai\Gateway\StepResponse|null>
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $body['stream'] = true;

        $streamOptions = $this->streamOptions($provider);
        if (Value::filled($streamOptions)) {
            $body['stream_options'] = $streamOptions;
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn() => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('chat/completions', $body),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    /**
     * Get the stream options sent with a streaming Chat Completions request.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>|null
     */
    protected function streamOptions(Provider $provider): ?array
    {
        return ['include_usage' => true];
    }
}
