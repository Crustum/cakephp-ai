<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Contracts\HasStructuredOutput;
use Crustum\Ai\Event\AgentStreamed;
use Crustum\Ai\Event\StreamingAgent;
use Crustum\Ai\Event\ToolApprovalRequested;
use Crustum\Ai\Event\ToolApprovalResolved;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Middleware\RememberConversation;
use Crustum\Ai\Pipeline\Pipeline;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use InvalidArgumentException;
use Throwable;

/**
 * Streams text responses from the provider's text gateway.
 */
trait StreamsTextTrait
{
    use ResumesToolApprovalsTrait;

    /**
     * Stream the response from the given agent.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return \Crustum\Ai\Responses\StreamableAgentResponse
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        $invocationId = $prompt->invocationId ?? Text::uuid();

        $resolvedApprovalResults = null;

        try {
            $response = (new Pipeline())
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($invocationId, &$resolvedApprovalResults): StreamableAgentResponse {

                    $agent = $prompt->agent;

                    if ($agent instanceof HasStructuredOutput) {
                        throw new InvalidArgumentException('Streaming structured output is not currently supported.');
                    }

                    $meta = new Meta($this->name(), $prompt->model);

                    $messages = $this->withoutForeignReplayBlocks([
                        ...($prompt->messages ?? []),
                        ...($agent instanceof Conversational ? $agent->messages() : []),
                    ]);

                    if (!$prompt->hasApprovalDecisions()) {
                        $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->toList());
                    }

                    $tools = $this->resolveTools($prompt);
                    $approval = $this->resumableApprovalFor($prompt);
                    $recordApprovalResults = $this->approvalResultRecorderFor($prompt, $resolvedApprovalResults);

                    $validatedApproval = $approval !== null
                        ? $this->textGenerationLoop()->validateApproval($approval, $messages, $tools)
                        : null;

                    $streamable = null;

                    $streamable = new StreamableAgentResponse(
                        $invocationId,
                        function () use ($invocationId, $prompt, $agent, $messages, $tools, $approval, $recordApprovalResults, $validatedApproval, &$streamable) {
                            $this->events->dispatch(new StreamingAgent($invocationId, $prompt));

                            try {
                                foreach (
                                    $this->textGenerationLoop()->stream(
                                        $invocationId,
                                        $this,
                                        $prompt->model,
                                        (string)$agent->instructions(),
                                        $messages,
                                        $tools,
                                        null,
                                        TextGenerationOptions::forAgent($agent),
                                        $prompt->timeout,
                                        $approval,
                                        $recordApprovalResults,
                                        $validatedApproval,
                                        $this->runContextFor($invocationId, $prompt),
                                    ) as $event
                                ) {
                                    if ($event instanceof ToolApprovalRequest) {
                                        $this->throwIfNotResumable($prompt);
                                    }

                                    yield $event;
                                }
                            } catch (Throwable $throwable) {
                                $this->recordAgentFailure($invocationId, $prompt, $throwable, retryable: !$streamable->hasYielded());

                                throw $throwable;
                            }
                        },
                        $meta,
                    );

                    if (RememberConversation::appliesTo($agent)) {
                        /** @var \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations $agent */
                        if ($agent->currentConversation() !== null) {
                            $streamable->withinConversation(
                                $agent->currentConversation(),
                                $agent->conversationParticipant(),
                            );
                        }
                    }

                    return $streamable;
                });
        } catch (Throwable $throwable) {
            $this->recordAgentFailure($invocationId, $prompt, $throwable);

            throw $throwable;
        }

        return $response->then(function (StreamedAgentResponse $response) use ($invocationId, $prompt, &$resolvedApprovalResults): void {
            $this->events->dispatch(
                new AgentStreamed($invocationId, $prompt, $response),
            );

            if ($response->hasPendingApprovals()) {
                $this->events->dispatch(new ToolApprovalRequested(
                    $invocationId,
                    $prompt->agent,
                    $response->pendingApprovals,
                    $response->conversationId,
                    $response->conversationUser,
                ));
            }

            if ($resolvedApprovalResults !== null) {
                $this->events->dispatch(new ToolApprovalResolved(
                    $invocationId,
                    $prompt->agent,
                    $resolvedApprovalResults,
                    $response->conversationId,
                    $response->conversationUser,
                ));
            }
        });
    }
}
