<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Streaming\Event\Citation;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Utility\Value;
use Generator;

/**
 * Handles Gemini streaming responses.
 */
trait HandlesTextStreamingTrait
{
    /**
     * Process a Gemini streaming response and yield stream events.
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
        $inReasoning = false;
        $currentText = '';
        $pendingToolCalls = [];
        $modelParts = [];
        $usage = null;
        $data = [];
        $citationData = [];

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            if (isset($data['error'])) {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['code'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            if (!$streamStartEmitted) {
                $streamStartEmitted = true;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['modelVersion'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $candidate = $data['candidates'][0] ?? [];
            $parts = $candidate['content']['parts'] ?? [];

            if (isset($candidate['groundingMetadata']) || isset($candidate['citationMetadata'])) {
                $citationData = $data;
            }

            foreach ($parts as $part) {
                if (isset($part['text']) && $this->isThinkingPart($part)) {
                    $modelParts[] = $part;
                    $delta = $part['text'];

                    if ($delta !== '') {
                        if (!$inReasoning) {
                            $inReasoning = true;
                            $reasoningId = $this->generateEventId();

                            yield (new ReasoningStart(
                                $this->generateEventId(),
                                $reasoningId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        yield (new ReasoningDelta(
                            $this->generateEventId(),
                            $reasoningId,
                            $delta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    continue;
                }

                if (isset($part['text'])) {
                    $modelParts[] = $part;

                    if ($inReasoning) {
                        $inReasoning = false;

                        yield (new ReasoningEnd(
                            $this->generateEventId(),
                            $reasoningId,
                            time(),
                        ))->withInvocationId($invocationId);

                        $reasoningId = '';
                    }

                    $textDelta = $part['text'];

                    if ($textDelta !== '') {
                        if (!$textStartEmitted) {
                            $textStartEmitted = true;

                            yield (new TextStart(
                                $this->generateEventId(),
                                $messageId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        $currentText .= $textDelta;

                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $textDelta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    continue;
                }

                if (isset($part['functionCall'])) {
                    $pendingToolCalls[] = $part['functionCall'];
                    $modelParts[] = $part;

                    continue;
                }
            }

            if (isset($data['usageMetadata'])) {
                $usage = $this->extractUsage($data);
            }
        }

        if ($inReasoning) {
            yield (new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        $toolCalls = [];

        if (Value::filled($pendingToolCalls)) {
            $toolCalls = $this->mapToolCalls($pendingToolCalls);

            foreach ($toolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        foreach ($this->extractCitations($citationData) as $citation) {
            yield (new Citation(
                $this->generateEventId(),
                $messageId,
                $citation,
                time(),
            ))->withInvocationId($invocationId);
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($data, $pendingToolCalls),
            usage: $usage ?? new Usage(0, 0),
            meta: new Meta($provider->name(), $model),
            providerContentBlocks: $this->sanitizeRequestParts($this->excludeThinkingParts($modelParts)),
        );
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
