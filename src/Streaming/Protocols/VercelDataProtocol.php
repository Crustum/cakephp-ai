<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Protocols;

use Cake\Http\Response;
use Crustum\Ai\Exception\InsufficientCreditsException;
use Crustum\Ai\Exception\ProviderConnectionException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Http\Stream\VercelProtocolStreamResponse;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Streaming\Event\Citation;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\ProviderToolEvent;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamEvent;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolCall;
use Crustum\Ai\Streaming\Event\ToolResult;
use Generator;
use Throwable;

/**
 * The Vercel AI SDK data stream protocol.
 *
 * See: https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
 */
class VercelDataProtocol extends StreamProtocol
{
    /**
     * Invocation identifier used when the stream starts without an explicit start part.
     */
    protected ?string $invocationId = null;

    /**
     * Constructor.
     *
     * @param string|null $messageId Client message id to continue
     */
    public function __construct(protected ?string $messageId = null)
    {
    }

    /**
     * Create an HTTP response that represents the given response using the protocol.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Cake\Http\Response
     */
    public function response(StreamableAgentResponse $response): Response
    {
        return new VercelProtocolStreamResponse($this->frames($response));
    }

    /**
     * Get the protocol parts that represent the given response's events.
     *
     * Resets and advances the stream state machine while streaming.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Generator<int, array<string, mixed>>
     * @phpstan-impure
     */
    protected function parts(StreamableAgentResponse $response): Generator
    {
        $this->started = false;
        $this->errored = false;
        $this->invocationId = $response->invocationId;

        $toolCalls = [];
        $reason = null;
        $usage = new TextUsage();

        foreach ($response as $event) {
            if ($event instanceof StreamStart && $this->started) {
                yield ['type' => 'finish-step'];
                yield ['type' => 'start-step'];

                continue;
            }

            if ($event instanceof ToolCall) {
                $toolCalls[$event->toolCall->id] = true;
            }

            if ($event instanceof Error) {
                $this->errored = true;
            }

            if (
                $event instanceof ToolResult
                && !isset($toolCalls[$event->toolResult->id])
                && $this->messageId === null
            ) {
                continue;
            }

            if ($event instanceof ToolApprovalRequest) {
                foreach ($event->pendingApprovals as $pendingApproval) {
                    yield from $this->yieldPart([
                        'type' => 'tool-approval-request',
                        'toolCallId' => $pendingApproval->id,
                        'approvalId' => $pendingApproval->id,
                        'reason' => $pendingApproval->reason,
                    ]);
                }

                continue;
            }

            if ($event instanceof StreamEnd) {
                $reason = $event->reason;
                $usage = $usage->add($event->usage);

                continue;
            }

            $part = $this->mapEvent($event);

            if (empty($part)) {
                continue;
            }

            yield from $this->yieldPart($part);
        }

        if ($this->started && !$this->errored) {
            yield ['type' => 'finish-step'];

            if ($reason !== null) {
                yield $this->finishPart($reason, $usage);
            }
        }
    }

    /**
     * @inheritDoc
     */
    protected function maskedErrorParts(): Generator
    {
        yield from $this->yieldPart([
            'type' => 'error',
            'errorText' => $this->maskedErrorMessage(),
            'errorCode' => $this->maskedErrorType(),
        ]);
    }

    /**
     * Map the interrupting failure to a client-facing error code.
     *
     * @return string
     */
    protected function maskedErrorType(): string
    {
        return match (true) {
            $this->failure instanceof RateLimitedException => 'rate_limited',
            $this->failure instanceof InsufficientCreditsException => 'insufficient_credits',
            $this->failure instanceof ProviderOverloadedException => 'provider_overloaded',
            $this->failure instanceof ProviderConnectionException => 'connection_error',
            default => 'stream_error',
        };
    }

    /**
     * Resolve the client-facing error message for the interrupting failure.
     *
     * Known provider/transport errors surface their real message; unexpected
     * failures are masked to avoid leaking internal details.
     *
     * @return string
     */
    protected function maskedErrorMessage(): string
    {
        if ($this->maskedErrorType() === 'stream_error' || !$this->failure instanceof Throwable) {
            return 'An error occurred.';
        }

        return $this->failure->getMessage();
    }

    /**
     * @inheritDoc
     */
    protected function terminator(): ?string
    {
        return "data: [DONE]\n\n";
    }

    /**
     * Get the given protocol part, preceded by a start part when one has not been sent yet.
     *
     * @param array<string, mixed> $part Protocol part
     * @phpstan-impure Emits the start part on first call.
     */
    protected function yieldPart(array $part): Generator
    {
        if ($part['type'] === 'start') {
            $this->started = true;

            $part['messageId'] = $this->messageId ?? $part['messageId'];

            yield $part;
            yield ['type' => 'start-step'];

            return;
        }

        if (!$this->started) {
            yield from $this->yieldPart(['type' => 'start', 'messageId' => $this->invocationId]);
        }

        yield $part;
    }

    /**
     * Get the protocol part that represents the given event.
     *
     * @param \Crustum\Ai\Streaming\Event\StreamEvent $event Stream event
     * @return array<string, mixed>|null
     */
    protected function mapEvent(StreamEvent $event): ?array
    {
        return match (true) {
            $event instanceof StreamStart => [
                'type' => 'start',
                'messageId' => $event->id,
            ],
            $event instanceof TextStart => [
                'type' => 'text-start',
                'id' => $event->messageId,
            ],
            $event instanceof TextDelta => [
                'type' => 'text-delta',
                'id' => $event->messageId,
                'delta' => $event->delta,
            ],
            $event instanceof TextEnd => [
                'type' => 'text-end',
                'id' => $event->messageId,
            ],
            $event instanceof ReasoningStart => [
                'type' => 'reasoning-start',
                'id' => $event->reasoningId,
            ],
            $event instanceof ReasoningDelta => [
                'type' => 'reasoning-delta',
                'id' => $event->reasoningId,
                'delta' => $event->delta,
            ],
            $event instanceof ReasoningEnd => [
                'type' => 'reasoning-end',
                'id' => $event->reasoningId,
            ],
            $event instanceof ToolCall => [
                'type' => 'tool-input-available',
                'toolCallId' => $event->toolCall->id,
                'toolName' => $event->toolCall->name,
                'input' => $event->toolCall->arguments,
            ],
            $event instanceof ToolResult => $this->toolResultPart($event),
            $event instanceof Citation => $this->citationPart($event),
            $event instanceof Error => [
                'type' => 'error',
                'errorText' => $event->message,
            ],
            $event instanceof ProviderToolEvent => $this->providerToolPart($event),
            default => null,
        };
    }

    /**
     * Get the protocol part that represents the given tool result event.
     *
     * @param \Crustum\Ai\Streaming\Event\ToolResult $event Tool result event
     * @return array<string, mixed>
     */
    protected function toolResultPart(ToolResult $event): array
    {
        if ($event->denied) {
            return [
                'type' => 'tool-output-denied',
                'toolCallId' => $event->toolResult->id,
            ];
        }

        if (!$event->successful) {
            return [
                'type' => 'tool-output-error',
                'toolCallId' => $event->toolResult->id,
                'errorText' => $event->error ?? 'The tool call failed.',
            ];
        }

        return [
            'type' => 'tool-output-available',
            'toolCallId' => $event->toolResult->id,
            'output' => $event->toolResult->result,
            ...($event->preliminary ? ['preliminary' => true] : []),
        ];
    }

    /**
     * Get the protocol part that represents the given provider tool event.
     *
     * @param \Crustum\Ai\Streaming\Event\ProviderToolEvent $event Provider tool event
     * @return array<string, mixed>
     */
    protected function providerToolPart(ProviderToolEvent $event): array
    {
        return [
            'type' => 'custom',
            'kind' => $event->provider . '.' . $event->type,
            'providerMetadata' => [
                $event->provider => [
                    'itemId' => $event->itemId,
                    'status' => $event->status,
                    'data' => $event->data,
                ],
            ],
        ];
    }

    /**
     * Get the protocol part that represents the given citation event.
     *
     * @param \Crustum\Ai\Streaming\Event\Citation $event Citation event
     * @return array<string, mixed>|null
     */
    protected function citationPart(Citation $event): ?array
    {
        return match (true) {
            $event->citation instanceof UrlCitation => array_filter([
                'type' => 'source-url',
                'sourceId' => $event->citation->url,
                'url' => $event->citation->url,
                'title' => $event->citation->title,
            ], fn($value): bool => $value !== null),
            default => null,
        };
    }

    /**
     * Get the protocol part that finishes the stream.
     *
     * @param string $reason Finish reason
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage Combined usage
     * @return array<string, mixed>
     */
    protected function finishPart(string $reason, TextUsage $usage): array
    {
        return [
            'type' => 'finish',
            'finishReason' => match ($reason) {
                'stop' => 'stop',
                'tool_calls' => 'tool-calls',
                'length' => 'length',
                'content_filter' => 'content-filter',
                'error' => 'error',
                default => 'other',
            },
            'messageMetadata' => [
                'usage' => [
                    'inputTokens' => $usage->inputTokens,
                    'outputTokens' => $usage->outputTokens,
                    'totalTokens' => $usage->totalTokens(),
                    'reasoningTokens' => $usage->reasoningTokens,
                    'cachedInputTokens' => $usage->cacheReadInputTokens,
                ],
            ],
        ];
    }
}
