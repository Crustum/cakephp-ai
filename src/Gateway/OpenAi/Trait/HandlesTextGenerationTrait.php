<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Streaming\Event\Citation as CitationEvent;
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
 * Handles OpenAI Responses API text streaming.
 */
trait HandlesTextGenerationTrait
{
    /**
     * Process an OpenAI Responses API text stream.
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
        $responseId = '';
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $reasoningItems = [];
        $usage = null;
        $responseData = [];

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $type = $data['type'] ?? '';

            if ($type === 'error') {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['code'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            if ($type === 'response.created' && !$streamStartEmitted) {
                $streamStartEmitted = true;
                $responseId = $data['response']['id'] ?? '';

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['response']['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($type === 'response.output_text.delta') {
                $textDelta = (string)($data['delta'] ?? '');
                $currentText .= $textDelta;

                if ($textDelta !== '') {
                    if (!$textStartEmitted) {
                        $textStartEmitted = true;

                        yield (new TextStart(
                            $this->generateEventId(),
                            $messageId,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    yield (new TextDelta(
                        $this->generateEventId(),
                        $messageId,
                        $textDelta,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                continue;
            }

            if ($type === 'response.output_text.annotation.added') {
                $annotation = $data['annotation'] ?? [];

                if (($annotation['type'] ?? '') === 'url_citation') {
                    yield (new CitationEvent(
                        $this->generateEventId(),
                        $messageId,
                        new UrlCitation(
                            $annotation['url'] ?? '',
                            $annotation['title'] ?? null,
                            isset($annotation['start_index']) ? (int)$annotation['start_index'] : null,
                            isset($annotation['end_index']) ? (int)$annotation['end_index'] : null,
                        ),
                        time(),
                    ))->withInvocationId($invocationId);
                }

                continue;
            }

            if ($type === 'response.output_text.done' && $textStartEmitted) {
                yield (new TextEnd(
                    $this->generateEventId(),
                    $messageId,
                    time(),
                ))->withInvocationId($invocationId);

                $textStartEmitted = false;
                $messageId = $this->generateEventId();

                continue;
            }

            if (in_array($type, ['response.reasoning_summary_text.delta', 'response.reasoning_text.delta'], true)) {
                $delta = (string)($data['delta'] ?? '');

                if ($delta !== '') {
                    if ($reasoningId === '') {
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

            if ($type === 'response.output_item.done' && ($data['item']['type'] ?? '') === 'reasoning') {
                $reasoningItems[] = [
                    'id' => $data['item']['id'] ?? null,
                    'summary' => $data['item']['summary'] ?? [],
                    'encrypted_content' => $data['item']['encrypted_content'] ?? null,
                ];

                if ($reasoningId !== '') {
                    yield (new ReasoningEnd(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);

                    $reasoningId = '';
                }

                continue;
            }

            if ($type === 'response.output_item.done') {
                $itemType = $data['item']['type'] ?? '';

                if ($itemType !== 'function_call' && str_ends_with((string)$itemType, '_call')) {
                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['item']['id'] ?? '',
                        $itemType,
                        $data['item'] ?? [],
                        'completed',
                        time(),
                        provider: $provider->name(),
                    ))->withInvocationId($invocationId);

                    continue;
                }
            }

            if (preg_match('/^response\.([a-z_]+_call)(_code)?\.(.+)$/', (string)$type, $matches) === 1) {
                yield (new ProviderToolEvent(
                    $this->generateEventId(),
                    $data['item_id'] ?? '',
                    $matches[1],
                    $data,
                    $matches[2] === '' ? $matches[3] : 'code_' . $matches[3],
                    time(),
                    provider: $provider->name(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if (($data['item']['type'] ?? '') === 'function_call' && $type === 'response.output_item.added') {
                $index = (int)($data['output_index'] ?? count($pendingToolCalls));

                $toolCall = [
                    'id' => $data['item']['id'] ?? null,
                    'call_id' => $data['item']['call_id'] ?? null,
                    'name' => $data['item']['name'] ?? null,
                    'arguments' => '',
                ];

                if (Value::filled($reasoningItems)) {
                    $latestReasoning = end($reasoningItems);

                    $toolCall['reasoning_id'] = $latestReasoning['id'];
                    $toolCall['reasoning_summary'] = $latestReasoning['summary'] ?? [];
                    $toolCall['reasoning_encrypted_content'] = $latestReasoning['encrypted_content'] ?? null;
                }

                $pendingToolCalls[$index] = $toolCall;

                continue;
            }

            if ($type === 'response.function_call_arguments.delta') {
                $callId = $data['item_id'] ?? null;

                foreach ($pendingToolCalls as &$call) {
                    if (($call['id'] ?? null) === $callId) {
                        $call['arguments'] .= $data['delta'] ?? '';
                        break;
                    }
                }

                unset($call);

                continue;
            }

            if ($type === 'response.function_call_arguments.done') {
                $callId = $data['item_id'] ?? null;
                $arguments = $data['arguments'] ?? '';

                foreach ($pendingToolCalls as &$call) {
                    if (($call['id'] ?? null) === $callId) {
                        if ($arguments !== '') {
                            $call['arguments'] = $arguments;
                        }

                        $toolCall = new ToolCall(
                            $call['id'],
                            $call['name'],
                            json_decode($call['arguments'], true) ?? [],
                            $call['call_id'] ?? null,
                            $call['reasoning_id'] ?? null,
                            $call['reasoning_summary'] ?? null,
                            $call['reasoning_encrypted_content'] ?? null,
                        );

                        $toolCalls[] = $toolCall;

                        yield (new ToolCallEvent(
                            $this->generateEventId(),
                            $toolCall,
                            time(),
                        ))->withInvocationId($invocationId);

                        break;
                    }
                }

                unset($call);

                continue;
            }

            if ($type === 'response.completed') {
                $response = $data['response'] ?? [];
                $responseData = $response;
                $responseId = $response['id'] ?? $responseId;

                $usage = $this->extractUsage($response);
            }
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($responseData),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $responseData['model'] ?? $model),
            continuationToken: $responseId,
            replayBlocks: $this->extractReplayBlocks($responseData['output'] ?? []),
            providerToolCalls: $this->extractProviderToolCalls($responseData['output'] ?? []),
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
