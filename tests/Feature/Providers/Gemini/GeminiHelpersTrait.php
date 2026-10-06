<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Feature\Providers\Gemini;

use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

trait GeminiHelpersTrait
{
    /**
     * Build a fake Gemini interaction response with the given steps.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     * @param array<string, mixed> $usage Usage overrides
     * @param string $status Interaction status
     */
    protected function fakeInteraction(array $steps, array $usage = [], string $status = 'completed'): array
    {
        return [
            'id' => 'int_123',
            'model' => 'gemini-3.7-flash',
            'status' => $status,
            'steps' => $steps,
            'usage' => array_merge([
                'total_input_tokens' => 10,
                'total_output_tokens' => 5,
                'total_tokens' => 15,
            ], $usage),
        ];
    }

    /**
     * Build a model output step with the given text.
     *
     * @param array<int, array<string, mixed>> $annotations Text annotations
     */
    protected function modelOutput(string $text, array $annotations = []): array
    {
        return [
            'type' => 'model_output',
            'status' => 'done',
            'content' => [array_filter([
                'type' => 'text',
                'text' => $text,
                'annotations' => $annotations ?: null,
            ])],
        ];
    }

    protected function thoughtStep(string $text): array
    {
        return [
            'type' => 'thought',
            'status' => 'done',
            'summary' => [['type' => 'text', 'text' => $text]],
        ];
    }

    protected function functionCallStep(string $name, array $arguments = [], string $id = 'call_123'): array
    {
        return [
            'type' => 'function_call',
            'status' => 'done',
            'id' => $id,
            'name' => $name,
            'arguments' => (object)$arguments,
        ];
    }

    protected function fakeTextResponse(string $text = 'Hello'): AiHttpResponseDefinition
    {
        return aiHttpResponse($this->fakeInteraction([$this->modelOutput($text)]));
    }

    /**
     * Build a fake Gemini response with the given steps.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     */
    protected function fakeThinkingResponse(array $steps): AiHttpResponseDefinition
    {
        return aiHttpResponse($this->fakeInteraction($steps, ['total_thought_tokens' => 3, 'total_tokens' => 18]));
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): AiHttpResponseDefinition
    {
        return aiHttpResponse($this->fakeInteraction([
            $this->functionCallStep($toolName, [], $callId ?? 'call_123'),
        ]));
    }

    protected function fakeStructuredResponse(array $data): AiHttpResponseDefinition
    {
        return aiHttpResponse($this->fakeInteraction([$this->modelOutput(json_encode($data))]));
    }

    protected function fakeUniqueToolCallResponse(): AiHttpResponseDefinition
    {
        return aiHttpResponse($this->fakeInteraction([
            $this->functionCallStep('FixedNumberGenerator', [], 'call_' . uniqid()),
        ]));
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent();

        $response = $agent->stream(
            'Hello',
            provider: 'gemini',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'data: ' . json_encode($event);
        }

        return implode("\n\n", $lines) . "\n\n";
    }

    protected function stepStart(int $index, array $step): array
    {
        return ['event_type' => 'step.start', 'index' => $index, 'step' => $step];
    }

    protected function stepDelta(int $index, string $type, string $text): array
    {
        return ['event_type' => 'step.delta', 'index' => $index, 'delta' => ['type' => $type, 'text' => $text]];
    }

    protected function argumentsDelta(int $index, string $partial): array
    {
        return ['event_type' => 'step.delta', 'index' => $index, 'delta' => ['type' => 'arguments_delta', 'arguments' => $partial]];
    }

    protected function stepStop(int $index): array
    {
        return ['event_type' => 'step.stop', 'index' => $index];
    }

    /**
     * Build a fake interaction completed event carrying usage and status only, never steps.
     *
     * @param array<string, mixed> $usage Usage overrides
     */
    protected function interactionCompleted(array $usage = [], string $status = 'completed'): array
    {
        return [
            'event_type' => 'interaction.completed',
            'interaction' => array_diff_key($this->fakeInteraction([], $usage, $status), ['steps' => true]),
        ];
    }
}
