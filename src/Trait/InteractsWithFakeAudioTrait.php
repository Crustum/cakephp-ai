<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Closure;
use Crustum\Ai\Gateway\FakeAudioGateway;
use Crustum\Ai\Prompts\AudioPrompt;
use Crustum\Ai\Prompts\QueuedAudioPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Interacts With Fake Audio Trait
 *
 * Provides methods for faking audio generation in tests.
 * Allows recording and asserting audio operations for testing purposes.
 */
trait InteractsWithFakeAudioTrait
{
    /**
     * The fake audio gateway instance.
     */
    protected ?FakeAudioGateway $fakeAudioGateway = null;

    /**
     * All of the recorded audio generations.
     *
     * @var array<\Crustum\Ai\Prompts\AudioPrompt>
     */
    protected array $recordedAudioGenerations = [];

    /**
     * All of the recorded audio generations that were queued.
     *
     * @var array<\Crustum\Ai\Prompts\QueuedAudioPrompt>
     */
    protected array $recordedQueuedAudioGenerations = [];

    /**
     * Fake audio generation.
     *
     * @param \Closure|array $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeAudioGateway
     */
    public function fakeAudio(Closure|array $responses = []): FakeAudioGateway
    {
        return $this->fakeAudioGateway = new FakeAudioGateway($responses);
    }

    /**
     * Record an audio generation.
     *
     * @param \Crustum\Ai\Prompts\AudioPrompt|\Crustum\Ai\Prompts\QueuedAudioPrompt $prompt The prompt
     * @return $this
     */
    public function recordAudioGeneration(AudioPrompt|QueuedAudioPrompt $prompt)
    {
        if ($prompt instanceof QueuedAudioPrompt) {
            $this->recordedQueuedAudioGenerations[] = $prompt;
        } else {
            $this->recordedAudioGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that audio was generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertAudioGenerated(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedAudioGenerations)->some(fn(AudioPrompt $prompt) => $callback($prompt)),
            'An expected audio generation was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that audio was not generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertAudioNotGenerated(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedAudioGenerations)->some(fn(AudioPrompt $prompt) => $callback($prompt)),
            'An unexpected audio generation was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no audio was generated.
     *
     * @return $this
     */
    public function assertNoAudioGenerated()
    {
        PHPUnit::assertEmpty(
            $this->recordedAudioGenerations,
            'Unexpected audio generations were recorded.',
        );

        return $this;
    }

    /**
     * Assert that a queued audio generation was recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertAudioQueued(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedQueuedAudioGenerations)->some(fn(QueuedAudioPrompt $prompt) => $callback($prompt)),
            'An expected queued audio generation was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a queued audio generation was not recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertAudioNotQueued(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedQueuedAudioGenerations)->some(fn(QueuedAudioPrompt $prompt) => $callback($prompt)),
            'An unexpected queued audio generation was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no queued audio generations were recorded.
     *
     * @return $this
     */
    public function assertNoAudioQueued()
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedAudioGenerations,
            'Unexpected queued audio generations were recorded.',
        );

        return $this;
    }

    /**
     * Determine if audio generation is faked.
     *
     * @return bool
     */
    public function audioIsFaked(): bool
    {
        return $this->fakeAudioGateway !== null;
    }

    /**
     * Get the fake audio gateway.
     */
    public function fakeAudioGateway(): ?FakeAudioGateway
    {
        return $this->fakeAudioGateway;
    }
}
