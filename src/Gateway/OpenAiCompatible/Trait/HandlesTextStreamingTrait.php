<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAiCompatible\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Utility\Value;
use Generator;

/**
 * Handles OpenAI-compatible Chat Completions text streaming.
 */
trait HandlesTextStreamingTrait
{
    /**
     * Process a Chat Completions streaming response for a single turn.
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
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;
        $responseModel = $model;

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

            $choice = $data['choices'][0] ?? null;

            if ($choice === null) {
                if (isset($data['usage'])) {
                    $usage = $this->extractUsage($data);
                }

                continue;
            }

            $delta = $choice['delta'] ?? [];

            if (!$streamStartEmitted) {
                $streamStartEmitted = true;
                $responseModel = $data['model'] ?? $model;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if (isset($delta['content']) && $delta['content'] !== '') {
                if (!$textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $delta['content'];

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $delta['content'],
                    time(),
                ))->withInvocationId($invocationId);
            }

            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tcDelta) {
                    $idx = $tcDelta['index'];

                    if (!isset($pendingToolCalls[$idx])) {
                        $pendingToolCalls[$idx] = [
                            'id' => $tcDelta['id'] ?? '',
                            'name' => $tcDelta['function']['name'] ?? '',
                            'arguments' => '',
                        ];
                    }

                    if (isset($tcDelta['function']['arguments'])) {
                        $pendingToolCalls[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                    }
                }
            }

            if (isset($choice['finish_reason'])) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = $this->extractUsage($data);
            }
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (Value::filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            $toolCalls = $this->mapStreamToolCalls($pendingToolCalls);

            foreach ($toolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason(['finish_reason' => $finishReason ?? '']),
            usage: $usage ?? new Usage(0, 0),
            meta: new Meta($provider->name(), $responseModel),
        );
    }

    /**
     * Map raw streaming tool call data to ToolCall DTOs.
     *
     * @param array<int, array<string, mixed>> $toolCalls Raw tool calls
     * @return array<int, \Crustum\Ai\Responses\Data\ToolCall>
     */
    protected function mapStreamToolCalls(array $toolCalls): array
    {
        return array_map(
            fn(array $toolCall): ToolCall => new ToolCall(
                $toolCall['id'] ?? '',
                $toolCall['name'] ?? '',
                json_decode($toolCall['arguments'] ?? '{}', true) ?? [],
                $toolCall['id'] ?? null,
            ),
            array_values($toolCalls),
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
