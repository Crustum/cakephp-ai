<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures;

use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Generator;

class CapturingStepGateway implements StepTextGateway
{
    /**
     * @var array<int, array{model: string, instructions: string|null, tools: array, schema: array|null, context: \Crustum\Ai\Gateway\StepContext}>
     */
    public array $calls = [];

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $this->calls[] = ['model' => $model, 'instructions' => $instructions, 'tools' => $tools, 'schema' => $schema, 'context' => $stepContext];

        return count($this->calls) === 1
            ? new StepResponse('', [new ToolCall('call_1', 'FixedNumberGenerator', [])], FinishReason::ToolCalls, new TextUsage(10, 5), new Meta(), continuationToken: 'resp_1')
            : new StepResponse('Done.', [], FinishReason::Stop, new TextUsage(), new Meta());
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        yield from [];
    }
}
