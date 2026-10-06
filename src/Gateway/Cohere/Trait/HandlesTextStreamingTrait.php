<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Cohere\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
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
 * Handles Cohere Chat API text streaming.
 */
trait HandlesTextStreamingTrait
{
    /**
     * Process a Cohere Chat API event stream.
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
        $reasoningId = null;
        $textStartEmitted = false;
        $currentText = '';
        $pendingToolCalls = [];
        $toolCalls = [];
        $usage = null;
        $finishReason = null;

        yield (new StreamStart(
            $this->generateEventId(),
            $provider->name(),
            $model,
            time(),
        ))->withInvocationId($invocationId);

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $delta = $data['delta'] ?? [];

            switch ($data['type'] ?? null) {
                case 'content-delta':
                    $thinking = (string)($delta['message']['content']['thinking'] ?? '');
                    $content = (string)($delta['message']['content']['text'] ?? '');

                    if ($thinking !== '') {
                        if ($reasoningId === null) {
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
                            $thinking,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    if ($content !== '') {
                        yield from $this->endReasoning($invocationId, $reasoningId);

                        if (!$textStartEmitted) {
                            $textStartEmitted = true;

                            yield (new TextStart(
                                $this->generateEventId(),
                                $messageId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        $currentText .= $content;

                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $content,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    break;

                case 'tool-call-start':
                    yield from $this->endReasoning($invocationId, $reasoningId);

                    $toolCall = $delta['message']['tool_calls'] ?? [];

                    $pendingToolCalls[$data['index']] = [
                        'id' => $toolCall['id'] ?? '',
                        'name' => $toolCall['function']['name'] ?? '',
                        'arguments' => (string)($toolCall['function']['arguments'] ?? ''),
                    ];

                    break;

                case 'tool-call-delta':
                    if (isset($pendingToolCalls[$data['index']])) {
                        $pendingToolCalls[$data['index']]['arguments'] .= (string)($delta['message']['tool_calls']['function']['arguments'] ?? '');
                    }

                    break;

                case 'message-end':
                    if (Value::filled($delta['error'] ?? null)) {
                        yield (new Error(
                            $this->generateEventId(),
                            strtolower($delta['finish_reason'] ?? 'error'),
                            $delta['error'],
                            false,
                            time(),
                        ))->withInvocationId($invocationId);

                        return null;
                    }

                    $finishReason = $delta['finish_reason'] ?? null;
                    $usage = $this->extractUsage($delta['usage'] ?? []);

                    break;
            }
        }

        yield from $this->endReasoning($invocationId, $reasoningId);

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        foreach ($pendingToolCalls as $pending) {
            $toolCall = new ToolCall(
                $pending['id'],
                $pending['name'],
                json_decode($pending['arguments'] !== '' ? $pending['arguments'] : '{}', true) ?? [],
                $pending['id'] !== '' ? $pending['id'] : null,
            );

            $toolCalls[] = $toolCall;

            yield (new ToolCallEvent(
                $this->generateEventId(),
                $toolCall,
                time(),
            ))->withInvocationId($invocationId);
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($finishReason),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $model),
        );
    }

    /**
     * Emit a reasoning end event if a reasoning block is open.
     *
     * @param string $invocationId Invocation identifier
     * @param string|null $reasoningId Reasoning identifier
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    protected function endReasoning(string $invocationId, ?string &$reasoningId): Generator
    {
        if ($reasoningId === null) {
            return;
        }

        yield (new ReasoningEnd(
            $this->generateEventId(),
            $reasoningId,
            time(),
        ))->withInvocationId($invocationId);

        $reasoningId = null;
    }

    /**
     * Generate a lowercase UUID for use as a stream event ID.
     *
     * @return string
     */
    protected function generateEventId(): string
    {
        return strtolower(Text::uuid());
    }
}
