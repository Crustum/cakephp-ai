<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Bedrock;

use Cake\Collection\Collection;
use Cake\Event\EventManagerInterface;
use Cake\Utility\Text;
use Crustum\Ai\Attributes\CacheInstructions;
use Crustum\Ai\Attributes\CacheToolDefinitions;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\Bedrock\Trait\CreatesBedrockClientTrait;
use Crustum\Ai\Gateway\Bedrock\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\Cohere\Trait\ParsesEmbeddingsTrait;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Gateway\Trait\DecodesStructuredOutputTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\StreamEvent;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Tools\ToolNameResolver;
use Crustum\Ai\Trait\JoinsReasoningTrait;
use Crustum\Ai\Utility\Value;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use Generator;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * AWS Bedrock Converse API text and embeddings gateway.
 */
class BedrockTextGateway implements EmbeddingGateway, StepTextGateway
{
    use CreatesBedrockClientTrait;
    use DecodesStructuredOutputTrait;
    use HandlesFailoverErrorsTrait;
    use JoinsReasoningTrait;
    use MapsAttachmentsTrait;
    use ParsesEmbeddingsTrait;

    /**
     * The name of the synthetic structured output tool.
     */
    protected const STRUCTURED_OUTPUT_TOOL = 'structured_output';

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Generate text for a single Converse step.
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
        $client = $this->createBedrockClient($provider, $timeout);

        $parameters = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn() => $client->converse($parameters),
            );

            $result = $response->toArray();
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        return $this->parseTextResponse($result, $provider, $model, Value::filled($schema));
    }

    /**
     * Stream text for a single Converse step.
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
        $client = $this->createBedrockClient($provider, $timeout);

        $parameters = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn() => $client->converseStream($parameters),
            );
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        return yield from $this->processTextStream($invocationId, $provider, $model, $response['stream'], Value::filled($schema));
    }

    /**
     * Build the Converse request parameters for the current text generation step.
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
        $conversationMessages = $this->formatMessages($messages);
        $schemaTools = $schema ? $this->buildSchemaTools($schema, $tools) : null;
        $formattedTools = $schemaTools === null && $tools !== [] ? $this->formatTools($tools) : null;

        return $this->buildConverseParameters(
            $model,
            $instructions,
            $conversationMessages,
            $schemaTools,
            $formattedTools,
            $tools === [],
            $options,
            $stepContext->isFinalStep,
        );
    }

    /**
     * Extract usage data from a Converse response.
     *
     * @param array<string, mixed> $data Converse response data
     * @return \Crustum\Ai\Responses\Data\TextUsage
     */
    protected function extractUsage(array $data): TextUsage
    {
        $usage = $data['usage'] ?? [];
        $cacheReadTokens = $usage['cacheReadInputTokens'] ?? null;
        $cacheWriteTokens = $usage['cacheWriteInputTokens'] ?? null;

        return new TextUsage(
            inputTokens: ($usage['inputTokens'] ?? 0) + ($cacheReadTokens ?? 0) + ($cacheWriteTokens ?? 0),
            outputTokens: $usage['outputTokens'] ?? 0,
            cacheReadInputTokens: $cacheReadTokens,
            cacheWriteInputTokens: $cacheWriteTokens,
        );
    }

    /**
     * Parse a single Converse response into a step response.
     *
     * @param array<string, mixed> $result Converse response data
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param bool $structured Whether structured output is active
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    protected function parseTextResponse(array $result, TextProvider $provider, string $model, bool $structured): StepResponse
    {
        $usage = $this->extractUsage($result);

        $output = '';
        $toolCalls = [];
        $replayBlocks = [];
        $structuredOutput = null;

        foreach ($result['output']['message']['content'] ?? [] as $block) {
            $replayBlocks[] = $block;

            if (isset($block['text'])) {
                $output .= $block['text'];

                continue;
            }

            if (!isset($block['toolUse'])) {
                continue;
            }

            if ($structured && $block['toolUse']['name'] === self::STRUCTURED_OUTPUT_TOOL) {
                $structuredOutput = json_encode($block['toolUse']['input'] ?? []);

                continue;
            }

            $toolCalls[] = new ToolCall(
                $block['toolUse']['toolUseId'],
                $block['toolUse']['name'],
                $block['toolUse']['input'] ?? [],
            );
        }

        $finishReason = $this->extractFinishReason($result);

        if ($toolCalls === [] && $structured && $finishReason === FinishReason::ToolCalls) {
            $finishReason = FinishReason::Stop;
        }

        return new StepResponse(
            text: $structuredOutput ?? $output,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model),
            structured: $structuredOutput !== null ? $this->decodeStructuredOutput($structuredOutput) : null,
            replayBlocks: $replayBlocks,
            reasoning: $this->extractReasoning($replayBlocks),
        );
    }

    /**
     * Extract the reasoning text from Converse content blocks.
     *
     * @param array<int, array<string, mixed>> $content Content blocks
     */
    protected function extractReasoning(array $content): string
    {
        /** @var \Cake\Collection\CollectionInterface<int, string> $texts */
        $texts = collection($content)
            ->map(fn(array $block): string => $block['reasoningContent']['reasoningText']['text'] ?? '');

        return static::joinReasoning($texts->toList());
    }

    /**
     * Stream a single Converse step, returning the parsed step response.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param mixed $stream Converse stream events
     * @param bool $structured Whether structured output is active
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent, mixed, \Crustum\Ai\Gateway\StepResponse>
     */
    protected function processTextStream(
        string $invocationId,
        TextProvider $provider,
        string $model,
        mixed $stream,
        bool $structured,
    ): Generator {
        $messageId = Text::uuid();
        $timestamp = time();
        $totalUsage = new TextUsage();

        yield (new StreamStart(
            Text::uuid(),
            $provider->name(),
            $model,
            $timestamp,
        ))->withInvocationId($invocationId);

        $assistantText = '';
        $pendingToolCalls = [];
        $toolCalls = [];
        $structuredOutput = null;
        $currentBlockIndex = null;
        $currentBlockType = '';
        $responseContent = [];
        $reasoningId = '';
        $textId = '';
        $currentText = '';
        $currentReasoningText = '';
        $currentReasoningSignature = '';
        $currentReasoningRedacted = '';
        $hasReasoningBlocks = false;
        $stopReason = 'stop';

        $emitTextStart = function () use (&$textId, $invocationId, $timestamp): ?StreamEvent {
            if ($textId !== '') {
                return null;
            }

            $textId = Text::uuid();

            return (new TextStart(
                Text::uuid(),
                $textId,
                $timestamp,
            ))->withInvocationId($invocationId);
        };

        $emitReasoningStart = function () use (&$reasoningId, $invocationId, $timestamp): ?StreamEvent {
            if ($reasoningId !== '') {
                return null;
            }

            $reasoningId = Text::uuid();

            return (new ReasoningStart(
                Text::uuid(),
                $reasoningId,
                $timestamp,
            ))->withInvocationId($invocationId);
        };

        foreach ($stream as $event) {
            if (isset($event['contentBlockStart'])) {
                $currentBlockIndex = $event['contentBlockStart']['contentBlockIndex'] ?? 0;
                $start = $event['contentBlockStart']['start'] ?? [];
                $currentBlockType = isset($start['toolUse']) ? 'toolUse' : '';

                if (isset($start['toolUse'])) {
                    $pendingToolCalls[$currentBlockIndex] = [
                        'id' => $start['toolUse']['toolUseId'] ?? '',
                        'name' => $start['toolUse']['name'] ?? '',
                        'input' => '',
                    ];
                }

                continue;
            }

            if (isset($event['contentBlockDelta'])) {
                $index = $event['contentBlockDelta']['contentBlockIndex'] ?? $currentBlockIndex;
                $delta = $event['contentBlockDelta']['delta'] ?? [];

                if (isset($delta['text'])) {
                    $currentBlockType = 'text';

                    if ($delta['text'] !== '') {
                        $emittedEvent = $emitTextStart();

                        if ($emittedEvent instanceof StreamEvent) {
                            yield $emittedEvent;
                        }

                        $assistantText .= $delta['text'];
                        $currentText .= $delta['text'];

                        yield (new TextDelta(
                            Text::uuid(),
                            $textId,
                            $delta['text'],
                            $timestamp,
                        ))->withInvocationId($invocationId);
                    }
                } elseif (isset($delta['reasoningContent']['text']) && $delta['reasoningContent']['text'] !== '') {
                    $currentBlockType = 'reasoning';
                    $hasReasoningBlocks = true;

                    $emittedEvent = $emitReasoningStart();

                    if ($emittedEvent instanceof StreamEvent) {
                        yield $emittedEvent;
                    }

                    $currentReasoningText .= $delta['reasoningContent']['text'];

                    yield (new ReasoningDelta(
                        Text::uuid(),
                        $reasoningId,
                        $delta['reasoningContent']['text'],
                        $timestamp,
                    ))->withInvocationId($invocationId);
                } elseif (isset($delta['reasoningContent']['signature']) && $delta['reasoningContent']['signature'] !== '') {
                    $currentBlockType = 'reasoning';
                    $hasReasoningBlocks = true;

                    $emittedEvent = $emitReasoningStart();

                    if ($emittedEvent instanceof StreamEvent) {
                        yield $emittedEvent;
                    }

                    $currentReasoningSignature .= $delta['reasoningContent']['signature'];
                } elseif (isset($delta['reasoningContent']['redactedContent']) && $delta['reasoningContent']['redactedContent'] !== '') {
                    $currentBlockType = 'reasoning';
                    $hasReasoningBlocks = true;

                    $emittedEvent = $emitReasoningStart();

                    if ($emittedEvent instanceof StreamEvent) {
                        yield $emittedEvent;
                    }

                    $currentReasoningRedacted .= $delta['reasoningContent']['redactedContent'];
                } elseif (isset($delta['toolUse']['input'], $pendingToolCalls[$index])) {
                    $pendingToolCalls[$index]['input'] .= $delta['toolUse']['input'];
                }

                continue;
            }

            if (isset($event['contentBlockStop'])) {
                $index = $event['contentBlockStop']['contentBlockIndex'] ?? $currentBlockIndex;

                if ($currentBlockType === 'reasoning') {
                    if ($currentReasoningRedacted !== '') {
                        $responseContent[$index] = [
                            'reasoningContent' => [
                                'redactedContent' => $currentReasoningRedacted,
                            ],
                        ];
                    } else {
                        $reasoningText = ['text' => $currentReasoningText];

                        if ($currentReasoningSignature !== '') {
                            $reasoningText['signature'] = $currentReasoningSignature;
                        }

                        $responseContent[$index] = [
                            'reasoningContent' => [
                                'reasoningText' => $reasoningText,
                            ],
                        ];
                    }

                    yield (new ReasoningEnd(
                        Text::uuid(),
                        $reasoningId,
                        $timestamp,
                    ))->withInvocationId($invocationId);

                    $currentReasoningText = '';
                    $currentReasoningSignature = '';
                    $currentReasoningRedacted = '';
                    $reasoningId = '';
                } elseif ($currentBlockType === 'text') {
                    $responseContent[$index] = ['text' => $currentText];

                    if ($textId !== '') {
                        yield (new TextEnd(
                            Text::uuid(),
                            $textId,
                            $timestamp,
                        ))->withInvocationId($invocationId);
                    }

                    $currentText = '';
                    $textId = '';
                } elseif ($currentBlockType === 'toolUse' && isset($pendingToolCalls[$index])) {
                    $pending = $pendingToolCalls[$index];
                    $arguments = json_decode($pending['input'] !== '' ? $pending['input'] : '{}', true) ?? [];

                    if ($structured && $pending['name'] === self::STRUCTURED_OUTPUT_TOOL) {
                        $structuredOutput = json_encode($arguments);
                    } else {
                        $toolCall = new ToolCall($pending['id'], $pending['name'], $arguments);
                        $toolCalls[] = $toolCall;
                        $responseContent[$index] = [
                            'toolUse' => [
                                'toolUseId' => $toolCall->id,
                                'name' => $toolCall->name,
                                'input' => $arguments,
                            ],
                        ];

                        yield (new ToolCallEvent(
                            Text::uuid(),
                            $toolCall,
                            $timestamp,
                        ))->withInvocationId($invocationId);
                    }

                    unset($pendingToolCalls[$index]);
                }

                $currentBlockType = '';

                continue;
            }

            if (isset($event['messageStop'])) {
                $stopReason = $event['messageStop']['stopReason'] ?? 'stop';

                continue;
            }

            if (isset($event['metadata']['usage'])) {
                $totalUsage = $totalUsage->add($this->extractUsage($event['metadata']));
            }
        }

        if ($structuredOutput !== null) {
            yield (new TextDelta(
                Text::uuid(),
                $messageId,
                $structuredOutput,
                $timestamp,
            ))->withInvocationId($invocationId);
        }

        $finishReason = $this->extractFinishReason(['stopReason' => $stopReason]);

        if ($toolCalls === [] && $structured && $finishReason === FinishReason::ToolCalls) {
            $finishReason = FinishReason::Stop;
        }

        $replayBlocks = array_values($responseContent);

        if (!$hasReasoningBlocks) {
            $replayBlocks = array_values(array_filter(
                $replayBlocks,
                fn(array $block): bool => !isset($block['text']) || $block['text'] !== '',
            ));
        }

        return new StepResponse(
            text: $assistantText,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $totalUsage,
            meta: new Meta($provider->name(), $model),
            structured: $structuredOutput !== null ? $this->decodeStructuredOutput($structuredOutput) : null,
            replayBlocks: $replayBlocks,
        );
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param array<int, string> $inputs Inputs to embed
     * @param int $dimensions Embedding dimensions
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $client = $this->createBedrockClient($provider, $timeout);

        if ($this->isCohereEmbeddingModel($model)) {
            return $this->generateCohereEmbeddings($provider, $model, $client, $inputs, $providerOptions);
        }

        $embeddings = [];
        $totalTokens = 0;

        foreach ($inputs as $input) {
            try {
                $response = $this->withErrorHandling(
                    $provider->name(),
                    fn() => $client->invokeModel([
                        'modelId' => $model,
                        'contentType' => 'application/json',
                        'accept' => 'application/json',
                        'body' => json_encode(array_merge($providerOptions, [
                            'inputText' => $input,
                            'dimensions' => $dimensions,
                        ])),
                    ]),
                );

                $result = json_decode((string)$response->get('body')->getContents(), true);
            } catch (Throwable $e) {
                throw BedrockException::toAiException($e, $provider->name(), $model);
            }

            if (isset($result['embedding'])) {
                $embeddings[] = $result['embedding'];
            }

            $totalTokens += $result['inputTextTokenCount'] ?? 0;
        }

        return new EmbeddingsResponse(
            $embeddings,
            new Usage($totalTokens),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate embeddings using a Cohere Bedrock model in a single batched call.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param mixed $client Bedrock runtime client
     * @param array<int, string> $inputs Inputs to embed
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    protected function generateCohereEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        mixed $client,
        array $inputs,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn() => $client->invokeModel([
                    'modelId' => $model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode(array_merge(
                        ['input_type' => 'search_document'],
                        $providerOptions,
                        ['texts' => array_values($inputs)],
                    )),
                ]),
            );

            $result = json_decode((string)$response->get('body')->getContents(), true);
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        // Cohere's Bedrock response body carries no usage, but the input token
        // count is reported in the `x-amzn-bedrock-input-token-count` header.
        $inputTokens = (int)($response->get('@metadata')['headers']['x-amzn-bedrock-input-token-count'] ?? 0);

        return new EmbeddingsResponse(
            $this->parseCohereEmbeddings($result['embeddings'] ?? []),
            new Usage($inputTokens),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Determine if the given model identifier refers to a Cohere embeddings model.
     *
     * @param string $model Model name
     * @return bool
     */
    protected function isCohereEmbeddingModel(string $model): bool
    {
        return str_contains($model, 'cohere.embed-');
    }

    /**
     * Resolve the maximum number of steps for the given tools and options.
     *
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @return int
     */
    protected function resolveMaxSteps(array $tools, ?TextGenerationOptions $options): int
    {
        if ($tools === []) {
            return 1;
        }

        if ($options?->maxSteps !== null) {
            return $options->maxSteps;
        }

        return (int)round(count($tools) * 1.5);
    }

    /**
     * Extract and map the finish reason from the Bedrock Converse response.
     *
     * @param array<string, mixed> $data Response data
     * @return \Crustum\Ai\Responses\Data\FinishReason
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['stopReason'] ?? '') {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            'content_filtered', 'guardrail_intervened' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Build the request parameters for the Bedrock Converse API.
     *
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, array<string, mixed>> $conversationMessages Conversation messages
     * @param array<int, array<string, mixed>>|null $schemaTools Schema tools
     * @param array<int, array<string, mixed>>|null $formattedTools Pre-formatted real tools (used when no schema is active)
     * @param bool $toolsEmpty Whether the caller passed any real tools at all
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param bool $isFinalStep Whether this is the final step
     * @return array<string, mixed>
     */
    protected function buildConverseParameters(
        string $model,
        ?string $instructions,
        array $conversationMessages,
        ?array $schemaTools,
        ?array $formattedTools,
        bool $toolsEmpty,
        ?TextGenerationOptions $options,
        bool $isFinalStep,
    ): array {
        $parameters = [
            'modelId' => $model,
            'messages' => $conversationMessages,
        ];

        if ($instructions) {
            $parameters['system'] = [['text' => $instructions]];
        }

        $toolConfig = $this->buildToolConfig($schemaTools, $formattedTools, $toolsEmpty, $isFinalStep);

        if ($toolConfig !== null) {
            $parameters['toolConfig'] = $toolConfig;
        }

        $inferenceConfig = $this->buildInferenceConfig($options);

        if ($inferenceConfig !== []) {
            $parameters['inferenceConfig'] = $inferenceConfig;
        }

        $providerOptions = $options?->providerOptions(Lab::Bedrock) ?? [];

        $parameters = array_merge($parameters, $providerOptions);

        $this->ensureValidPromptCacheOrder($options);

        if (isset($parameters['system']) && $options?->cacheInstructions instanceof CacheInstructions) {
            $parameters['system'][] = $this->cachePoint($options->cacheInstructions->ttl);
        }

        if (isset($parameters['toolConfig']['tools']) && $options?->cacheToolDefinitions instanceof CacheToolDefinitions) {
            $parameters['toolConfig']['tools'][] = $this->cachePoint($options->cacheToolDefinitions->ttl);
        }

        return $parameters;
    }

    /**
     * Ensure longer-lived cache points precede shorter-lived cache points.
     *
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @return void
     * @throws \InvalidArgumentException When cache TTL ordering is invalid
     */
    protected function ensureValidPromptCacheOrder(?TextGenerationOptions $options): void
    {
        if (
            $options?->cacheInstructions?->ttl === '1h'
            && $options->cacheToolDefinitions instanceof CacheToolDefinitions
            && $options->cacheToolDefinitions->ttl !== '1h'
        ) {
            throw new InvalidArgumentException('A one-hour instructions cache requires the tool definitions cache to also use a one-hour TTL.');
        }
    }

    /**
     * Build a Bedrock cache point for the requested TTL.
     *
     * @param string|null $ttl Cache TTL
     * @return array<string, mixed>
     */
    protected function cachePoint(?string $ttl): array
    {
        return ['cachePoint' => array_filter(['type' => 'default', 'ttl' => $ttl])];
    }

    /**
     * Build the inferenceConfig block for Bedrock's Converse API.
     *
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @return array<string, mixed>
     */
    protected function buildInferenceConfig(?TextGenerationOptions $options): array
    {
        if (!$options instanceof TextGenerationOptions) {
            return [];
        }

        return array_filter([
            'maxTokens' => $options->maxTokens,
            'temperature' => $options->temperature,
            'topP' => $options->topP,
        ], fn($value): bool => $value !== null);
    }

    /**
     * Build the assistant conversation message block combining text and tool calls.
     *
     * @param string $text Assistant text
     * @param array<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls Tool calls
     * @param array<int, array<string, mixed>> $replayBlocks Provider content blocks
     * @return array<string, mixed>
     */
    protected function buildAssistantConversationMessage(string $text, array $toolCalls, array $replayBlocks = []): array
    {
        return $this->formatAssistantMessage(
            new AssistantMessage($text, new Collection($toolCalls), $replayBlocks),
        );
    }

    /**
     * Cast empty toolUse.input arrays to objects so the Converse API doesn't reject them.
     *
     * @param array<int, array<string, mixed>> $content Content blocks
     * @return array<int, array<string, mixed>>
     */
    protected function ensureToolInputIsObject(array $content): array
    {
        return array_map(function (array $block): array {
            if (isset($block['toolUse'])) {
                $block['toolUse']['input'] = (object)($block['toolUse']['input'] ?? []);
            }

            return $block;
        }, $content);
    }

    /**
     * Build the user conversation message block carrying tool results.
     *
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Tool results
     * @return array<string, mixed>
     */
    protected function buildToolResultConversationMessage(array $toolResults): array
    {
        return [
            'role' => 'user',
            'content' => array_map(fn(ToolResult $toolResult): array => [
                'toolResult' => [
                    'toolUseId' => $toolResult->id,
                    'content' => [
                        ['text' => $toolResult->text()],
                    ],
                ],
            ], $toolResults),
        ];
    }

    /**
     * Build the synthetic structured-output tool plus any real tools.
     *
     * @param array<string, mixed> $schema Structured output schema
     * @param array<int, mixed> $tools Available tools
     * @return array<int, array<string, mixed>>
     */
    protected function buildSchemaTools(array $schema, array $tools): array
    {
        $schemaTools = [
            [
                'toolSpec' => [
                    'name' => self::STRUCTURED_OUTPUT_TOOL,
                    'description' => 'Return the response as a structured JSON object matching the provided schema.',
                    'inputSchema' => [
                        'json' => (new ObjectSchema($schema))->toArray(),
                    ],
                ],
            ],
        ];

        return array_merge($schemaTools, $this->formatTools($tools));
    }

    /**
     * Build Bedrock's toolConfig for the current step.
     *
     * @param array<int, array<string, mixed>>|null $schemaTools Schema tools
     * @param array<int, array<string, mixed>>|null $formattedTools Pre-formatted real tools
     * @param bool $toolsEmpty Whether any real tools were passed
     * @param bool $isFinalStep Whether this is the final step
     * @return array<string, mixed>|null
     */
    protected function buildToolConfig(?array $schemaTools, ?array $formattedTools, bool $toolsEmpty, bool $isFinalStep): ?array
    {
        if ($schemaTools !== null) {
            return [
                'tools' => $schemaTools,
                'toolChoice' => $isFinalStep || $toolsEmpty
                    ? ['tool' => ['name' => self::STRUCTURED_OUTPUT_TOOL]]
                    : ['auto' => []],
            ];
        }

        if ($formattedTools !== null) {
            return ['tools' => $formattedTools];
        }

        return null;
    }

    /**
     * Format messages for Bedrock's Converse API.
     *
     * @param array<int, mixed> $messages Conversation messages
     * @return array<int, array<string, mixed>>
     */
    protected function formatMessages(array $messages): array
    {
        return (new Collection($messages))->map(fn(AssistantMessage|ToolResultMessage|UserMessage|Message|array $message): array => match (true) {
            $message instanceof AssistantMessage => $this->formatAssistantMessage($message),
            $message instanceof ToolResultMessage => $this->formatToolResultMessage($message),
            $message instanceof UserMessage => $this->formatUserMessage($message),
            $message instanceof Message => $this->formatGenericMessage($message),
            default => $this->formatArrayMessage($message),
        })->toList();
    }

    /**
     * Format an AssistantMessage for the Converse API.
     *
     * @param \Crustum\Ai\Messages\AssistantMessage $message Assistant message
     * @return array<string, mixed>
     */
    protected function formatAssistantMessage(AssistantMessage $message): array
    {
        if (Value::filled($message->replayBlocks)) {
            return [
                'role' => 'assistant',
                'content' => $this->ensureToolInputIsObject($message->replayBlocks),
            ];
        }

        $content = [];

        if (!empty($message->content)) {
            $content[] = ['text' => $message->content];
        }

        foreach ($message->toolCalls as $toolCall) {
            $content[] = [
                'toolUse' => [
                    'toolUseId' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => $toolCall->arguments ?: new stdClass(),
                ],
            ];
        }

        return ['role' => 'assistant', 'content' => $content];
    }

    /**
     * Format a ToolResultMessage for the Converse API.
     *
     * @param \Crustum\Ai\Messages\ToolResultMessage $message Tool result message
     * @return array<string, mixed>
     */
    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        $content = [];

        foreach ($message->toolResults as $toolResult) {
            $content[] = [
                'toolResult' => [
                    'toolUseId' => $toolResult->id,
                    'content' => [
                        ['text' => $toolResult->text()],
                    ],
                ],
            ];
        }

        return ['role' => 'user', 'content' => $content];
    }

    /**
     * Format a UserMessage and its attachments for the Converse API.
     *
     * @param \Crustum\Ai\Messages\UserMessage $message User message
     * @return array<string, mixed>
     */
    protected function formatUserMessage(UserMessage $message): array
    {
        $content = [['text' => $message->content]];

        if (!$message->attachments->isEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments));
        }

        return ['role' => 'user', 'content' => $content];
    }

    /**
     * Format a generic Message (system/user/assistant) for the Converse API.
     *
     * @param \Crustum\Ai\Messages\Message $message Message instance
     * @return array<string, mixed>
     */
    protected function formatGenericMessage(Message $message): array
    {
        return [
            'role' => $message->role === MessageRole::Assistant ? 'assistant' : 'user',
            'content' => [['text' => $message->content]],
        ];
    }

    /**
     * Format a raw array-shaped message for the Converse API.
     *
     * @param array{role: string, content: string} $message Raw message
     * @return array<string, mixed>
     */
    protected function formatArrayMessage(array $message): array
    {
        return [
            'role' => $message['role'] === MessageRole::Assistant->value ? 'assistant' : 'user',
            'content' => [['text' => $message['content']]],
        ];
    }

    /**
     * Format tools for the Converse API.
     *
     * @param array<int, mixed> $tools Available tools
     * @return array<int, array<string, mixed>>
     */
    protected function formatTools(array $tools): array
    {
        return (new Collection($tools))
            ->filter(fn($tool): bool => $tool instanceof Tool)
            ->map(fn(Tool $tool): array => [
                'toolSpec' => [
                    'name' => ToolNameResolver::resolve($tool),
                    'description' => (string)$tool->description(),
                    'inputSchema' => [
                        'json' => (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory())))->toArray(),
                    ],
                ],
            ])
            ->values()
            ->toList();
    }
}
