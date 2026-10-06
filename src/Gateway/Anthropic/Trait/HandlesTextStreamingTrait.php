<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Anthropic\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Streaming\Event\Citation as CitationEvent;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\ProviderToolEvent;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\StreamEvent;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Generator;

/**
 * Handles Anthropic Messages API text streaming.
 */
trait HandlesTextStreamingTrait
{
    /**
     * Process a single Anthropic streaming turn and yield stream events.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param object $streamBody Stream body
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent, mixed, \Crustum\Ai\Gateway\StepResponse|null>
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        object $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $reasoningStartEmitted = false;

        $currentBlockType = '';
        $currentBlockIndex = -1;
        $currentBlockText = '';
        $currentThinkingText = '';
        $currentSignature = '';
        $currentToolIndex = -1;
        $currentServerToolInput = '';
        $pendingToolCalls = [];
        $responseContent = [];

        $messageUsage = [];
        $usage = null;
        $stopReason = '';

        $emitTextStart = function () use (&$textStartEmitted, $messageId, $invocationId): ?StreamEvent {
            if ($textStartEmitted) {
                return null;
            }

            $textStartEmitted = true;

            return (new TextStart(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        };

        $emitReasoningStart = function () use (&$reasoningStartEmitted, &$reasoningId, $invocationId): ?StreamEvent {
            if ($reasoningStartEmitted) {
                return null;
            }

            $reasoningStartEmitted = true;
            $reasoningId = $this->generateEventId();

            return (new ReasoningStart(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        };

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $type = $data['type'] ?? '';

            if ($type === 'error') {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['type'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            if ($type === 'message_start' && !$streamStartEmitted) {
                $streamStartEmitted = true;

                $messageUsage = $data['message']['usage'] ?? [];

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['message']['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($type === 'content_block_start') {
                $blockType = $data['content_block']['type'] ?? '';
                $currentBlockType = $blockType;
                $currentBlockIndex = $data['index'] ?? count($responseContent);

                if ($blockType === 'text') {
                    $currentBlockText = '';

                    $event = $emitTextStart();

                    if ($event instanceof StreamEvent) {
                        yield $event;
                    }
                } elseif ($blockType === 'thinking') {
                    $currentThinkingText = '';
                    $currentSignature = '';

                    $event = $emitReasoningStart();

                    if ($event instanceof StreamEvent) {
                        yield $event;
                    }
                } elseif ($blockType === 'tool_use') {
                    $currentToolIndex++;

                    $pendingToolCalls[$currentToolIndex] = [
                        'id' => $data['content_block']['id'] ?? '',
                        'name' => $data['content_block']['name'] ?? '',
                        'arguments' => '',
                    ];
                } elseif ($blockType === 'server_tool_use') {
                    $currentServerToolInput = '';

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['content_block']['id'] ?? '',
                        $blockType,
                        $data['content_block'] ?? [],
                        'started',
                        time(),
                        provider: $provider->name(),
                    ))->withInvocationId($invocationId);
                } elseif ($this->isProviderToolResultBlock($blockType)) {
                    $fetchResult = $data['content_block']['content'] ?? [];

                    if ($blockType === 'web_fetch_tool_result' && ($fetchResult['type'] ?? '') === 'web_fetch_result' && filled($fetchResult['url'] ?? null)) {
                        yield (new CitationEvent(
                            $this->generateEventId(),
                            $messageId,
                            new UrlCitation($fetchResult['url'], $fetchResult['content']['title'] ?? null),
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['content_block']['tool_use_id'] ?? $data['content_block']['id'] ?? '',
                        $blockType,
                        $data['content_block'] ?? [],
                        'result_received',
                        time(),
                        provider: $provider->name(),
                    ))->withInvocationId($invocationId);
                }

                if (isset($data['content_block'])) {
                    $responseContent[$data['index'] ?? count($responseContent)] = $data['content_block'];
                }

                continue;
            }

            if ($type === 'content_block_delta') {
                $deltaType = $data['delta']['type'] ?? '';

                if ($deltaType === 'text_delta') {
                    $textDelta = (string)($data['delta']['text'] ?? '');

                    if ($textDelta !== '') {
                        $event = $emitTextStart();

                        if ($event instanceof StreamEvent) {
                            yield $event;
                        }

                        $currentBlockText .= $textDelta;

                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $textDelta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'thinking_delta') {
                    $delta = (string)($data['delta']['thinking'] ?? '');

                    if ($delta !== '') {
                        $event = $emitReasoningStart();

                        if ($event instanceof StreamEvent) {
                            yield $event;
                        }

                        $currentThinkingText .= $delta;

                        yield (new ReasoningDelta(
                            $this->generateEventId(),
                            $reasoningId,
                            $delta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'signature_delta') {
                    $currentSignature .= (string)($data['delta']['signature'] ?? '');
                } elseif ($deltaType === 'citations_delta' && $currentBlockType === 'text') {
                    $citationData = $data['delta']['citation'] ?? null;

                    if ($citationData && ($citationData['type'] ?? '') === 'web_search_result_location') {
                        yield (new CitationEvent(
                            $this->generateEventId(),
                            $messageId,
                            new UrlCitation($citationData['url'] ?? '', $citationData['title'] ?? null),
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    $partial = (string)($data['delta']['partial_json'] ?? '');

                    if ($currentBlockType === 'tool_use' && isset($pendingToolCalls[$currentToolIndex])) {
                        $pendingToolCalls[$currentToolIndex]['arguments'] .= $partial;
                    } elseif ($currentBlockType === 'server_tool_use') {
                        $currentServerToolInput .= $partial;
                    }
                }

                continue;
            }

            if ($type === 'content_block_stop') {
                if ($currentBlockType === 'text') {
                    if (isset($responseContent[$currentBlockIndex])) {
                        $responseContent[$currentBlockIndex]['text'] = $currentBlockText;
                    }
                } elseif ($currentBlockType === 'thinking' && $reasoningStartEmitted) {
                    if (isset($responseContent[$currentBlockIndex])) {
                        $responseContent[$currentBlockIndex]['thinking'] = $currentThinkingText;
                        $responseContent[$currentBlockIndex]['signature'] = $currentSignature;
                    }

                    yield (new ReasoningEnd(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);

                    $reasoningStartEmitted = false;
                    $reasoningId = '';
                } elseif ($currentBlockType === 'tool_use' && isset($pendingToolCalls[$currentToolIndex])) {
                    $call = $pendingToolCalls[$currentToolIndex];
                    $parsedArguments = json_decode($call['arguments'] ?: '{}', true) ?? [];

                    $index = $data['index'] ?? $currentToolIndex;

                    if (isset($responseContent[$index])) {
                        $responseContent[$index]['input'] = $parsedArguments;
                    }

                    yield (new ToolCallEvent(
                        $this->generateEventId(),
                        new ToolCall(
                            $call['id'],
                            $call['name'],
                            $parsedArguments,
                            $call['id'],
                        ),
                        time(),
                    ))->withInvocationId($invocationId);
                } elseif ($currentBlockType === 'server_tool_use') {
                    $index = $data['index'] ?? count($responseContent) - 1;

                    if ($currentServerToolInput !== '' && isset($responseContent[$index])) {
                        $responseContent[$index]['input'] = json_decode($currentServerToolInput, true) ?? [];
                    }

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $responseContent[$index]['id'] ?? '',
                        $currentBlockType,
                        $responseContent[$index] ?? [],
                        'completed',
                        time(),
                        provider: $provider->name(),
                    ))->withInvocationId($invocationId);
                }

                $currentBlockType = '';

                continue;
            }

            if ($type === 'message_delta') {
                $stopReason = $data['delta']['stop_reason'] ?? '';

                // TextUsage on message_delta is cumulative for the whole message...
                $usage = $this->extractUsage(['usage' => array_merge($messageUsage, $data['usage'] ?? [])]);
            }
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        return $this->buildStepResponse(
            content: array_values($responseContent),
            provider: $provider,
            model: $model,
            usage: $usage ?? new TextUsage(0, 0),
            finishReason: $this->extractFinishReason(['stop_reason' => $stopReason]),
            structured: false,
        );
    }

    /**
     * Determine if the given block type is a provider tool result.
     *
     * @param string $blockType Block type
     * @return bool
     */
    protected function isProviderToolResultBlock(string $blockType): bool
    {
        return str_ends_with($blockType, '_tool_result');
    }

    /**
     * Generate a stream event identifier.
     *
     * @return string
     */
    protected function generateEventId(): string
    {
        return strtolower(Text::uuid());
    }
}
