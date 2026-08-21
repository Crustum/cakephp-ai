<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Trait;

use Cake\Http\Response;
use Cake\Log\Log;
use Crustum\Ai\Exception\InsufficientCreditsException;
use Crustum\Ai\Exception\ProviderConnectionException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Http\Stream\VercelProtocolStreamResponse;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolCall;
use Crustum\Ai\Streaming\Event\ToolResult;
use Generator;
use Throwable;

/**
 * Can Stream Using Vercel Protocol Trait
 *
 * Enables streaming responses using the Vercel AI SDK protocol.
 */
trait CanStreamUsingVercelProtocolTrait
{
    /**
     * Create an HTTP response that represents the object using the Vercel AI SDK protocol
     *
     * @return \Cake\Http\Response
     */
    protected function toVercelProtocolResponse(): Response
    {
        $state = new class
        {
            public bool $streamStarted = false;

            public array $toolCalls = [];

            public ?StreamEnd $lastStreamEnd = null;

            public ?Usage $usage = null;

            public bool $errored = false;
        };

        return new VercelProtocolStreamResponse($this->generateVercelProtocolParts($state));
    }

    /**
     * Generate the protocol parts for the stream.
     *
     * @param object $state Streaming state accumulator
     * @return \Generator
     */
    protected function generateVercelProtocolParts(object $state): Generator
    {
        try {
            foreach ($this as $event) {
                if ($event instanceof StreamStart && $state->streamStarted) {
                    yield $this->toVercelProtocolFormat(['type' => 'finish-step']);
                    yield $this->toVercelProtocolFormat(['type' => 'start-step']);

                    continue;
                }

                if ($event instanceof ToolCall) {
                    $state->toolCalls[$event->toolCall->id] = true;
                }

                if ($event instanceof Error) {
                    $state->errored = true;
                }

                if (
                    $event instanceof ToolResult &&
                    !isset($state->toolCalls[$event->toolResult->id]) &&
                    $this->vercelProtocolMessageId === null
                ) {
                    continue;
                }

                if ($event instanceof ToolApprovalRequest) {
                    foreach ($event->pendingApprovals as $pendingApproval) {
                        yield from $this->toVercelProtocolPart($state, [
                            'type' => 'tool-approval-request',
                            'toolCallId' => $pendingApproval->id,
                            'approvalId' => $pendingApproval->id,
                            'reason' => $pendingApproval->reason,
                        ]);
                    }

                    continue;
                }

                if ($event instanceof StreamEnd) {
                    $state->lastStreamEnd = $event;
                    $state->usage = ($state->usage ?? new Usage())->add($event->usage);

                    continue;
                }

                $data = $event->toVercelProtocolArray();

                if (empty($data)) {
                    continue;
                }

                yield from $this->toVercelProtocolPart($state, $data);
            }

            if ($state->streamStarted && !$state->errored) {
                yield $this->toVercelProtocolFormat(['type' => 'finish-step']);

                if ($state->lastStreamEnd && $state->usage) {
                    yield $this->toVercelProtocolFormat((new StreamEnd(
                        $state->lastStreamEnd->id,
                        $state->lastStreamEnd->reason,
                        $state->usage,
                        $state->lastStreamEnd->timestamp,
                    ))->toVercelProtocolArray());
                }
            }
        } catch (Throwable $throwable) {
            if (!$throwable instanceof StreamErrorException) {
                Log::error('Vercel stream failed: ' . $throwable->getMessage());
            }

            if (!$state->errored) {
                yield from $this->toVercelProtocolPart($state, [
                    'type' => 'error',
                    'errorText' => $this->vercelErrorMessage($throwable),
                    'errorCode' => $this->vercelErrorType($throwable),
                ]);
            }
        }

        yield "data: [DONE]\n\n";
    }

    /**
     * Map a stream failure to a client-facing error code for the Vercel protocol.
     *
     * @param \Throwable $throwable The failure
     * @return string
     */
    private function vercelErrorType(Throwable $throwable): string
    {
        return match (true) {
            $throwable instanceof RateLimitedException => 'rate_limited',
            $throwable instanceof InsufficientCreditsException => 'insufficient_credits',
            $throwable instanceof ProviderOverloadedException => 'provider_overloaded',
            $throwable instanceof ProviderConnectionException => 'connection_error',
            default => 'stream_error',
        };
    }

    /**
     * Resolve the client-facing error message for a stream failure.
     *
     * Known provider/transport errors surface their real message; unexpected
     * failures are masked to avoid leaking internal details.
     *
     * @param \Throwable $throwable The failure
     * @return string
     */
    private function vercelErrorMessage(Throwable $throwable): string
    {
        return $this->vercelErrorType($throwable) === 'stream_error'
            ? 'An error occurred.'
            : $throwable->getMessage();
    }

    /**
     * Encode the given protocol part, preceded by a start part when one has not been sent yet.
     *
     * @param object $state Streaming state accumulator
     * @param array<string, mixed> $data Protocol part payload
     * @return \Generator
     */
    protected function toVercelProtocolPart(object $state, array $data): Generator
    {
        if ($data['type'] === 'start') {
            $state->streamStarted = true;

            $data['messageId'] = $this->vercelProtocolMessageId ?? $data['messageId'];

            yield $this->toVercelProtocolFormat($data);
            yield $this->toVercelProtocolFormat(['type' => 'start-step']);

            return;
        }

        if (!$state->streamStarted) {
            yield from $this->toVercelProtocolPart($state, ['type' => 'start', 'messageId' => $this->invocationId]);
        }

        yield $this->toVercelProtocolFormat($data);
    }

    /**
     * Encode the given protocol part as a server-sent event line.
     *
     * @param array<string, mixed> $data Protocol part payload
     * @return string
     */
    protected function toVercelProtocolFormat(array $data): string
    {
        return 'data: ' . json_encode($data) . "\n\n";
    }
}
