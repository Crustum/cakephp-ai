<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Gateway\TextGenerationLoop;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\StreamableAgentResponse;

/**
 * Text Provider Interface
 *
 * Defines contract for providers that support text generation capabilities.
 */
interface TextProvider extends Provider
{
    /**
     * Invoke the given agent.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return \Crustum\Ai\Responses\AgentResponse
     */
    public function prompt(AgentPrompt $prompt): AgentResponse;

    /**
     * Stream the response from the given agent.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return \Crustum\Ai\Responses\StreamableAgentResponse
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse;

    /**
     * Set the provider's text gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\StepTextGateway $gateway Text gateway
     * @return $this
     */
    public function useTextGateway(StepTextGateway $gateway);

    /**
     * Get the multi-step text generation loop wrapping the provider's text gateway.
     *
     * @return \Crustum\Ai\Gateway\TextGenerationLoop
     */
    public function textGenerationLoop(): TextGenerationLoop;

    /**
     * Get the name of the default text model.
     *
     * @return string
     */
    public function defaultTextModel(): string;

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string;

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string;
}
