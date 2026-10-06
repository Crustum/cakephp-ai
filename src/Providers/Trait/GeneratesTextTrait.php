<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Closure;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Contracts\HasSkills;
use Crustum\Ai\Contracts\HasStructuredOutput;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Event\AgentFailed;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\PromptingAgent;
use Crustum\Ai\Event\ToolApprovalRequested;
use Crustum\Ai\Event\ToolApprovalResolved;
use Crustum\Ai\Exception\ApprovalNotResumableException;
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
use Crustum\Ai\Tools\LoadSkill;
use Crustum\Ai\Tools\McpServerTool;
use Crustum\Ai\Tools\McpTool;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use Crustum\Mcp\Client\Primitives\Tool as McpClientTool;
use Crustum\Mcp\Server\Tool as McpServerToolContract;
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

        $resolvedApprovalResults = null;

        try {
            $response = (new Pipeline())
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($invocationId, &$resolvedApprovalResults): AgentResponse {

                    $this->events->dispatch(new PromptingAgent($invocationId, $prompt));

                    $agent = $prompt->agent;

                    $messages = $this->withoutForeignReplayBlocks([
                        ...($prompt->messages ?? []),
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
                        $this->resolveTools($prompt),
                        $schema,
                        TextGenerationOptions::forAgent($agent),
                        $prompt->timeout,
                        $this->resumableApprovalFor($prompt),
                        $this->approvalResultRecorderFor($prompt, $resolvedApprovalResults),
                        $this->runContextFor($invocationId, $prompt),
                    );

                    if ($response->hasPendingApprovals()) {
                        $this->throwIfNotResumable($prompt);
                    }

                    $agentResponse = $response instanceof StructuredTextResponse
                    ? (new StructuredAgentResponse($invocationId, $response->structured, $response->text, $response->usage, $response->meta))
                        ->withMessages($response->messages)
                        ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                        ->withSteps($response->steps)
                        ->withReasoning($response->reasoning)
                        ->withRawResponse($response->raw)
                    : (new AgentResponse($invocationId, $response->text, $response->usage, $response->meta))
                        ->withMessages($response->messages)
                        ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                        ->withSteps($response->steps)
                        ->withReasoning($response->reasoning)
                        ->withRawResponse($response->raw);

                    $agentResponse->withPendingApprovals($response->pendingApprovals);

                    return $agentResponse;
                });
        } catch (Throwable $throwable) {
            $this->recordAgentFailure($invocationId, $prompt, $throwable);

            throw $throwable;
        }

        $this->events->dispatch(
            new AgentPrompted($invocationId, $prompt, $response),
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
     * Gather the internal run middleware for the given agent.
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

        if (RememberConversation::appliesTo($agent)) {
            $middleware[] = new RememberConversation(Ai::manager()->conversationStore(), $this);
        }

        return $middleware;
    }

    /**
     * Resolve the tools for the given prompt, wrapping any agent instances as tools.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return array<int, mixed>
     */
    protected function resolveTools(AgentPrompt $prompt): array
    {
        return array_map(
            fn($tool) => $this->resolveTool($tool),
            $prompt->tools ?? $this->declaredTools($prompt->agent),
        );
    }

    /**
     * Get the tools the agent declares, including the tool that loads its skills.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @return array<int, mixed>
     */
    protected function declaredTools(Agent $agent): array
    {
        $tools = $agent instanceof HasTools ? [...$agent->tools()] : [];

        return $agent instanceof HasSkills ? LoadSkill::mergeInto($tools, $agent) : $tools;
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
            $tool instanceof McpClientTool => new McpTool($tool),
            $tool instanceof McpServerToolContract => new McpServerTool($tool),
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
        $context = new RunContext($invocationId, $prompt->agent, $this, $prompt->model, $this->events);

        $prompt->setRunContext($context);

        return $context;
    }

    /**
     * Dispatch the terminal failure event for a run, unless the caller may still retry it against another provider.
     *
     * @param string $invocationId Invocation ID
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Throwable $exception The failure
     * @param bool $retryable Whether the caller may retry against another provider
     */
    protected function recordAgentFailure(string $invocationId, AgentPrompt $prompt, Throwable $exception, bool $retryable = true): void
    {
        // A failoverable exception is only terminal once the caller has run out of providers to try.
        if ($retryable && $prompt->willRetry($exception)) {
            return;
        }

        $this->events->dispatch(
            new AgentFailed($invocationId, $prompt, $exception),
        );
    }

    /**
     * Throw when a pause has surfaced on a prompt that cannot be resumed from persisted or replayed history.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return void
     * @throws \Crustum\Ai\Exception\ApprovalNotResumableException
     */
    protected function throwIfNotResumable(AgentPrompt $prompt): void
    {
        if (!$prompt->agent instanceof Conversational && $prompt->messages === null) {
            throw ApprovalNotResumableException::make();
        }
    }
}
