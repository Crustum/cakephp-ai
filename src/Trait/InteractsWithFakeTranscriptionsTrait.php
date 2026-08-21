<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Closure;
use Crustum\Ai\Gateway\FakeTranscriptionGateway;
use Crustum\Ai\Prompts\QueuedTranscriptionPrompt;
use Crustum\Ai\Prompts\TranscriptionPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Interacts With Fake Transcriptions Trait
 *
 * Provides methods for faking transcription operations in tests.
 * Allows recording and asserting transcription operations for testing purposes.
 */
trait InteractsWithFakeTranscriptionsTrait
{
    /**
     * The fake transcription gateway instance.
     */
    protected ?FakeTranscriptionGateway $fakeTranscriptionGateway = null;

    /**
     * All of the recorded transcription generations.
     *
     * @var array<\Crustum\Ai\Prompts\TranscriptionPrompt>
     */
    protected array $recordedTranscriptionGenerations = [];

    /**
     * All of the recorded transcription generations that were queued.
     *
     * @var array<\Crustum\Ai\Prompts\QueuedTranscriptionPrompt>
     */
    protected array $recordedQueuedTranscriptionGenerations = [];

    /**
     * Fake transcription generation.
     *
     * @param \Closure|array $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeTranscriptionGateway
     */
    public function fakeTranscriptions(Closure|array $responses = []): FakeTranscriptionGateway
    {
        $this->recordedTranscriptionGenerations = [];
        $this->recordedQueuedTranscriptionGenerations = [];

        return $this->fakeTranscriptionGateway = new FakeTranscriptionGateway($responses);
    }

    /**
     * Record a transcription generation.
     *
     * @param \Crustum\Ai\Prompts\TranscriptionPrompt|\Crustum\Ai\Prompts\QueuedTranscriptionPrompt $prompt The prompt
     * @return $this
     */
    public function recordTranscriptionGeneration(TranscriptionPrompt|QueuedTranscriptionPrompt $prompt)
    {
        if ($prompt instanceof QueuedTranscriptionPrompt) {
            $this->recordedQueuedTranscriptionGenerations[] = $prompt;
        } else {
            $this->recordedTranscriptionGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that a transcription was generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertTranscriptionGenerated(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedTranscriptionGenerations)->some(fn(TranscriptionPrompt $prompt) => $callback($prompt)),
            'An expected transcription generation was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a transcription was not generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertTranscriptionNotGenerated(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedTranscriptionGenerations)->some(fn(TranscriptionPrompt $prompt) => $callback($prompt)),
            'An unexpected transcription generation was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no transcriptions were generated.
     *
     * @return $this
     */
    public function assertNoTranscriptionsGenerated()
    {
        PHPUnit::assertEmpty(
            $this->recordedTranscriptionGenerations,
            'Unexpected transcription generations were recorded.',
        );

        return $this;
    }

    /**
     * Assert that a queued transcription generation was recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertTranscriptionQueued(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedQueuedTranscriptionGenerations)->some(fn(QueuedTranscriptionPrompt $prompt) => $callback($prompt)),
            'An expected queued transcription generation was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a queued transcription generation was not recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertTranscriptionNotQueued(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedQueuedTranscriptionGenerations)->some(fn(QueuedTranscriptionPrompt $prompt) => $callback($prompt)),
            'An unexpected queued transcription generation was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no queued transcription generations were recorded.
     *
     * @return $this
     */
    public function assertNoTranscriptionsQueued()
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedTranscriptionGenerations,
            'Unexpected queued transcription generations were recorded.',
        );

        return $this;
    }

    /**
     * Determine if transcription generation is faked.
     *
     * @return bool
     */
    public function transcriptionsAreFaked(): bool
    {
        return $this->fakeTranscriptionGateway !== null;
    }

    /**
     * Get the fake transcription gateway.
     */
    public function fakeTranscriptionGateway(): ?FakeTranscriptionGateway
    {
        return $this->fakeTranscriptionGateway;
    }
}
