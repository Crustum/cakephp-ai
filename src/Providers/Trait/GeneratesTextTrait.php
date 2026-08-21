<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Closure;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Contracts\HasMiddleware;
use Crustum\Ai\Contracts\HasStructuredOutput;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Event\AgentFailedEvent;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\PromptingAgent;
use Crustum\Ai\Event\ToolApprovalRequested;
use Crustum\Ai\Event\ToolApprovalResolved;
use Crustum\Ai\Exception\ApprovalNotResumableException;
use Crustum\Ai\Exception\FailoverableException;
use Crustum\Ai\Gateway\RunContext;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Middleware\RememberConversation;
use Crustum\Ai\Pipeline\Pipeline;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\StructuredAgentResponse;
use Crustum\Ai\Responses\StructuredTextResponse;
use Crustum\Ai\Tools\AgentTool;
use Crustum\Ai\Trait\RemembersConversationsTrait;
use Crustum\Ai\Utility\Reflection;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use Throwable;

/**
 * Generates text through the provider's text gateway.
 */
trait GeneratesTextTrait
{
    use ResumesToolApprovalsTrait;

    /**
     * Invoke the given agent.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return \Crustum\Ai\Responses\AgentResponse
     */
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        $invocationId = $prompt->invocationId ?? Text::uuid();

        $processedPrompt = null;
        $resolvedApprovalResults = null;

        try {
            $response = (new Pipeline())
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($invocationId, &$processedPrompt, &$resolvedApprovalResults): AgentResponse {
                    $processedPrompt = $prompt;

                    $this->events->dispatch(new PromptingAgent($invocationId, $prompt));

                    $agent = $prompt->agent;

                    $messages = $this->withoutForeignProviderContentBlocks([
                    ...($agent instanceof Conversational ? $agent->messages() : []),
                    ]);

                    if (!$prompt->hasApprovalDecisions()) {
                        $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->toList());
                    }

                    $schema = $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory()) : null;

                    $response = $this->textGenerationLoop()->generate(
                        $this,
                        $prompt->model,
                        (string)$agent->instructions(),
                        $messages,
                        $this->resolveTools($agent),
                        $schema,
                        TextGenerationOptions::forAgent($agent),
                        $prompt->timeout,
                        $this->resumableApprovalFor($prompt),
                        $this->approvalResultRecorderFor($prompt, $resolvedApprovalResults),
                        $this->runContextFor($invocationId, $prompt),
                    );

                    if ($response->hasPendingApprovals()) {
                        $this->throwIfNotResumable($agent);
                    }

                    $agentResponse = $response instanceof StructuredTextResponse
                    ? (new StructuredAgentResponse($invocationId, $response->structured, $response->text, $response->usage, $response->meta))
                        ->withMessages($response->messages)
                        ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                        ->withSteps($response->steps)
                        ->withRawResponse($response->raw)
                    : (new AgentResponse($invocationId, $response->text, $response->usage, $response->meta))
                        ->withMessages($response->messages)
                        ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                        ->withSteps($response->steps)
                        ->withRawResponse($response->raw);

                    $agentResponse->withPendingApprovals($response->pendingApprovals);

                    return $agentResponse;
                });
        } catch (Throwable $throwable) {
            $this->recordAgentFailure($invocationId, $prompt, $throwable, $processedPrompt);

            throw $throwable;
        }

        $this->events->dispatch(
            new AgentPrompted($invocationId, $processedPrompt ?? $prompt, $response),
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

        return $response;
    }

    /**
     * Gather the middleware for the given agent.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @return array<int, mixed>
     */
    protected function gatherMiddlewareFor(Agent $agent): array
    {
        $middleware = Ai::manager()->hasFakeGatewayFor($agent::class) ? [function (AgentPrompt $prompt, Closure $next) {
            Ai::manager()->recordPrompt($prompt);

            return $next($prompt);
        }] : [];

        if (in_array(RemembersConversationsTrait::class, Reflection::classUsesRecursive($agent), true)) {
            $middleware[] = new RememberConversation(Ai::manager()->conversationStore(), $this);
        }

        return $agent instanceof HasMiddleware
            ? [...$middleware, ...$agent->middleware()]
            : $middleware;
    }

    /**
     * Resolve the tools for the given agent, wrapping any agent instances as tools.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @return array<int, mixed>
     */
    protected function resolveTools(Agent $agent): array
    {
        if (!$agent instanceof HasTools) {
            return [];
        }

        return array_map(
            fn($tool) => $this->resolveTool($tool),
            [...$agent->tools()],
        );
    }

    /**
     * Resolve a tool returned by the agent into a native tool instance when needed.
     *
     * @param mixed $tool Tool definition
     */
    protected function resolveTool(mixed $tool): mixed
    {
        return match (true) {
            $tool instanceof Agent => new AgentTool($tool),
            $tool instanceof Tool => $tool,
            $tool instanceof ToolSearch => $tool->withTools(
                array_map(fn($nested) => $this->resolveTool($nested), $tool->tools),
            ),
            default => $tool,
        };
    }

    /**
     * Build the context that identifies this run and reports its tool / step events.
     *
     * @param string $invocationId Invocation ID
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return \Crustum\Ai\Gateway\RunContext
     */
    protected function runContextFor(string $invocationId, AgentPrompt $prompt): RunContext
    {
        return new RunContext($invocationId, $prompt->agent, $this, $prompt->model, $this->events);
    }

    /**
     * Dispatch the terminal failure event for a run, unless the caller may still retry it against another provider.
     *
     * @param string $invocationId Invocation ID
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Throwable $exception The failure
     * @param \Crustum\Ai\Prompts\AgentPrompt|null $processedPrompt Prompt as processed by middleware
     * @param bool $retryable Whether the caller may retry against another provider
     */
    protected function recordAgentFailure(string $invocationId, AgentPrompt $prompt, Throwable $exception, ?AgentPrompt $processedPrompt = null, bool $retryable = true): void
    {
        if (
            $retryable &&
            !$prompt->isFinalAttempt() &&
            $exception instanceof FailoverableException
        ) {
            return;
        }

        $this->events->dispatch(
            new AgentFailedEvent($invocationId, $processedPrompt ?? $prompt, $exception),
        );
    }

    /**
     * Throw when a pause has surfaced on an agent that cannot resume it from persisted history.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @return void
     * @throws \Crustum\Ai\Exception\ApprovalNotResumableException
     */
    protected function throwIfNotResumable(Agent $agent): void
    {
        if (!$this->agentCanResumeApprovals($agent)) {
            throw ApprovalNotResumableException::make();
        }
    }

    /**
     * Determine whether the given agent can resume a paused approval from persisted history.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @return bool
     */
    protected function agentCanResumeApprovals(Agent $agent): bool
    {
        return $agent instanceof Conversational;
    }
}
