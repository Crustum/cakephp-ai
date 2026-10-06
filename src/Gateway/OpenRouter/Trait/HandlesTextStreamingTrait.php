<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenRouter\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Streaming\Event\Citation as CitationEvent;
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
 * Handles OpenRouter text streaming responses.
 */
trait HandlesTextStreamingTrait
{
    /**
     * Process an OpenRouter text stream.
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
        $streamModel = $model;
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;

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

            if (($choice['finish_reason'] ?? null) === 'error') {
                $error = $choice['error'] ?? [];

                yield (new Error(
                    $this->generateEventId(),
                    (string)($error['code'] ?? 'provider_error'),
                    $error['message'] ?? 'An upstream provider error occurred.',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            if (!$streamStartEmitted) {
                $streamStartEmitted = true;
                $streamModel = $data['model'] ?? $model;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $streamModel,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $reasoning = $delta['reasoning'] ?? '';

            if ($reasoning === '') {
                $reasoning = $this->reasoningTextIn($delta['reasoning_details'] ?? []);
            }

            if ($reasoning !== '') {
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
                    $reasoning,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if ($reasoningId !== null && ((isset($delta['content']) && $delta['content'] !== '') || isset($delta['tool_calls']))) {
                yield (new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                ))->withInvocationId($invocationId);

                $reasoningId = null;
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

                    $pendingToolCalls[$idx] ??= [
                        'id' => $tcDelta['id'] ?? '',
                        'name' => $tcDelta['function']['name'] ?? '',
                        'arguments' => '',
                    ];

                    if (isset($tcDelta['function']['arguments'])) {
                        $pendingToolCalls[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                    }
                }
            }

            if (isset($delta['annotations'])) {
                foreach ($delta['annotations'] as $annotation) {
                    if (($annotation['type'] ?? '') === 'url_citation') {
                        $urlCitation = $annotation['url_citation'] ?? [];

                        yield (new CitationEvent(
                            $this->generateEventId(),
                            $messageId,
                            new UrlCitation(
                                $urlCitation['url'] ?? '',
                                $urlCitation['title'] ?? null,
                                isset($urlCitation['start_index']) ? (int)$urlCitation['start_index'] : null,
                                isset($urlCitation['end_index']) ? (int)$urlCitation['end_index'] : null,
                            ),
                            time(),
                        ))->withInvocationId($invocationId);
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

        if ($reasoningId !== null) {
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

        if (Value::filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            foreach (array_values($pendingToolCalls) as $pending) {
                $toolCall = new ToolCall(
                    $pending['id'],
                    $pending['name'],
                    json_decode($pending['arguments'], true) ?? [],
                    $pending['id'],
                );

                $toolCalls[] = $toolCall;

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
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $streamModel),
        );
    }

    /**
     * Get the human readable reasoning carried by a delta's reasoning details.
     *
     * @param array<int, array<string, mixed>> $details Reasoning details
     */
    protected function reasoningTextIn(array $details): string
    {
        /** @var \Cake\Collection\CollectionInterface<int, string> $texts */
        $texts = collection($details)
            ->map(fn(array $detail): string => (string)($detail['text'] ?? $detail['summary'] ?? ''));

        return implode('', $texts->toList());
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
