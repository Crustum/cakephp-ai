<?php
declare(strict_types=1);

use Crustum\Ai\Job\BroadcastAgentJob;
use Crustum\Ai\Job\GenerateAudioJob;
use Crustum\Ai\Job\GenerateEmbeddingsJob;
use Crustum\Ai\Job\GenerateImageJob;
use Crustum\Ai\Job\GenerateTranscriptionJob;
use Crustum\Ai\Job\InvokeAgentJob;

test('ai jobs are stateless and container-safe', function (
    string $jobClass,
): void {
    $reflection = new ReflectionClass($jobClass);
    $constructor = $reflection->getConstructor();
    $parameters = $constructor?->getParameters() ?? [];

    // The queue worker builds jobs with no arguments through the container.
    // Any constructor parameter (especially union/mixed types, which League
    // Container cannot reflect) reproduces the production crash loop, so jobs
    // must not declare any.
    expect($parameters)->toBeEmpty()
        ->and(new $jobClass())->toBeInstanceOf($jobClass);
})->with([
    BroadcastAgentJob::class,
    InvokeAgentJob::class,
    GenerateAudioJob::class,
    GenerateImageJob::class,
    GenerateEmbeddingsJob::class,
    GenerateTranscriptionJob::class,
]);
