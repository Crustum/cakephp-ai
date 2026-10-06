<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Ollama\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Responses\Data\FinishReason;
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
 * Handles Ollama NDJSON text streaming.
 */
trait HandlesTextStreamingTrait
{
    /**
     * Process an Ollama NDJSON streaming response and yield stream events.
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
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $usage = null;
        $lastData = [];

        foreach ($this->parseNdjsonStream($streamBody) as $data) {
            if (isset($data['error'])) {
                $error = $data['error'];
                $isStructured = is_array($error);

                yield (new Error(
                    $this->generateEventId(),
                    $isStructured ? ($error['code'] ?? 'unknown_error') : 'unknown_error',
                    $isStructured ? ($error['message'] ?? 'Unknown error') : (string)$error,
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
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $thinking = $data['message']['thinking'] ?? '';
            $content = $data['message']['content'] ?? '';

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

            if ($reasoningId !== null && ($content !== '' || !empty($data['message']['tool_calls']))) {
                yield (new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                ))->withInvocationId($invocationId);

                $reasoningId = null;
            }

            if ($content !== '') {
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

            if (!empty($data['message']['tool_calls'])) {
                foreach ($data['message']['tool_calls'] as $index => $toolCall) {
                    $pendingToolCalls[$index] ??= [
                        'id' => null,
                        'name' => '',
                        'arguments' => [],
                        'argumentsBuffer' => '',
                    ];

                    if (isset($toolCall['id'])) {
                        $pendingToolCalls[$index]['id'] = $toolCall['id'];
                    }

                    $name = $toolCall['function']['name'] ?? '';

                    if ($name !== '') {
                        $pendingToolCalls[$index]['name'] = $name;
                    }

                    if (array_key_exists('arguments', $toolCall['function'] ?? [])) {
                        $arguments = $toolCall['function']['arguments'];

                        if (is_array($arguments)) {
                            $pendingToolCalls[$index]['arguments'] = array_replace(
                                $pendingToolCalls[$index]['arguments'],
                                $arguments,
                            );
                        } elseif (is_string($arguments)) {
                            $pendingToolCalls[$index]['argumentsBuffer'] .= $arguments;
                        }
                    }
                }
            }

            if (isset($data['prompt_eval_count']) || isset($data['eval_count'])) {
                $usage = $this->extractUsage($data);
            }

            if ($data['done'] ?? false) {
                $lastData = $data;

                break;
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

        if (Value::filled($pendingToolCalls)) {
            $toolCalls = array_map(function (array $toolCall): ToolCall {
                $arguments = $toolCall['arguments'];

                if ($toolCall['argumentsBuffer'] !== '') {
                    $decoded = json_decode((string)$toolCall['argumentsBuffer'], true);

                    if (is_array($decoded)) {
                        $arguments = array_replace($arguments, $decoded);
                    }
                }

                $id = $toolCall['id'] ?? Text::uuid();

                return new ToolCall($id, $toolCall['name'], $arguments, $id);
            }, array_values($pendingToolCalls));

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
            finishReason: Value::filled($toolCalls) ? FinishReason::ToolCalls : $this->extractFinishReason($lastData),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $lastData['model'] ?? $model),
        );
    }

    /**
     * Parse an Ollama NDJSON stream, yielding each parsed JSON object.
     *
     * @param object $streamBody Stream with eof() and read() methods
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    protected function parseNdjsonStream(object $streamBody): Generator
    {
        $buffer = '';

        while (!$streamBody->eof()) {
            $buffer .= $streamBody->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);

                if ($data !== null) {
                    yield $data;
                }
            }
        }

        if (Value::filled(trim($buffer))) {
            $data = json_decode(trim($buffer), true);

            if ($data !== null) {
                yield $data;
            }
        }
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
