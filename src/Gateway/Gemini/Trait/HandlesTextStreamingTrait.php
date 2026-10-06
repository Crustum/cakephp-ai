<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Streaming\Event\Citation;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\ProviderToolEvent;
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
        $steps = [];
        $partialArguments = [];
        $final = [];

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $event = $data['event_type'] ?? '';

            if ($event === 'error' || isset($data['error'])) {
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
                    $data['interaction']['model'] ?? $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if ($event === 'interaction.completed') {
                $final = $data['interaction'] ?? $data;

                continue;
            }

            $index = $data['index'] ?? 0;

            if (isset($data['step']) && is_array($data['step'])) {
                $steps[$index] = array_merge($steps[$index] ?? [], $data['step']);
            }

            $delta = $data['delta'] ?? null;

            if (is_array($delta)) {
                $deltaType = $delta['type'] ?? '';

                // Thought signatures, provider tool arguments and their results all arrive as delta keys.
                foreach (array_diff_key($delta, array_flip(['type', 'text', 'content'])) as $key => $value) {
                    $steps[$index][$key] = $value;
                }

                if ($deltaType === 'thought' || $deltaType === 'thought_summary') {
                    // A thought summary nests its text one level deeper than a plain text delta.
                    $reasoningDelta = (string)($delta['content']['text'] ?? $delta['text'] ?? '');

                    $this->appendStepText($steps, $index, 'thought', $reasoningDelta, 'summary');

                    if ($reasoningDelta !== '') {
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
                            $reasoningDelta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'text') {
                    $textDelta = (string)($delta['text'] ?? '');

                    $this->appendStepText($steps, $index, 'model_output', $textDelta);

                    if ($inReasoning) {
                        $inReasoning = false;

                        yield (new ReasoningEnd(
                            $this->generateEventId(),
                            $reasoningId,
                            time(),
                        ))->withInvocationId($invocationId);

                        $reasoningId = '';
                    }

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
                } elseif ($deltaType === 'arguments_delta') {
                    // Gemini splits function call arguments across deltas as partial JSON strings.
                    $partialArguments[$index] = ($partialArguments[$index] ?? '') . ($delta['arguments'] ?? '');

                    $steps[$index]['type'] ??= 'function_call';
                    $steps[$index]['arguments'] = $this->decodeArguments($partialArguments[$index]);
                }
            }

            if ($event === 'step.stop' && $this->isProviderToolStep($steps[$index] ?? [])) {
                yield (new ProviderToolEvent(
                    $this->generateEventId(),
                    (string)($steps[$index]['id'] ?? ''),
                    (string)preg_replace('/_(call|result)$/', '', $steps[$index]['type']),
                    $steps[$index],
                    str_ends_with($steps[$index]['type'], '_result') ? 'result_received' : 'completed',
                    time(),
                    provider: $provider->name(),
                ))->withInvocationId($invocationId);
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

        $steps = array_values($steps);

        $functionCallSteps = $this->extractFunctionCallSteps($steps);
        $toolCalls = $this->mapToolCalls($functionCallSteps, $this->thoughtSignature($steps));

        foreach ($toolCalls as $toolCall) {
            yield (new ToolCallEvent(
                $this->generateEventId(),
                $toolCall,
                time(),
            ))->withInvocationId($invocationId);
        }

        foreach ($this->extractCitations($steps) as $citation) {
            yield (new Citation(
                $this->generateEventId(),
                $messageId,
                $citation,
                time(),
            ))->withInvocationId($invocationId);
        }

        return new StepResponse(
            text: $currentText !== '' ? $currentText : $this->extractText($steps),
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($final, $functionCallSteps),
            usage: Value::filled($final) ? $this->extractUsage($final) : new TextUsage(0, 0),
            meta: new Meta($provider->name(), $model),
            replayBlocks: $this->replayableSteps($steps),
            reasoning: $this->extractReasoning($steps),
            providerToolCalls: $this->extractProviderToolCalls($steps),
        );
    }

    /**
     * Append streamed text to the step being accumulated at the given index.
     *
     * @param array<int|string, array<string, mixed>> $steps Accumulated steps
     * @param string|int $index Step index
     * @param string $type Step type
     * @param string $text Text to append
     * @param string $key Content key
     * @return void
     */
    protected function appendStepText(array &$steps, int|string $index, string $type, string $text, string $key = 'content'): void
    {
        $steps[$index]['type'] ??= $type;
        $steps[$index][$key][0]['type'] ??= 'text';
        $steps[$index][$key][0]['text'] = ($steps[$index][$key][0]['text'] ?? '') . $text;
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
