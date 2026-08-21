<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Closure;
use Crustum\Ai\Gateway\FakeImageGateway;
use Crustum\Ai\Prompts\ImagePrompt;
use Crustum\Ai\Prompts\QueuedImagePrompt;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Interacts With Fake Images Trait
 *
 * Provides methods for faking image generation in tests.
 * Allows recording and asserting image operations for testing purposes.
 */
trait InteractsWithFakeImagesTrait
{
    /**
     * The fake image gateway instance.
     */
    protected ?FakeImageGateway $fakeImageGateway = null;

    /**
     * All of the recorded image generations.
     *
     * @var array<\Crustum\Ai\Prompts\ImagePrompt>
     */
    protected array $recordedImageGenerations = [];

    /**
     * All of the recorded image generations that were queued.
     *
     * @var array<\Crustum\Ai\Prompts\QueuedImagePrompt>
     */
    protected array $recordedQueuedImageGenerations = [];

    /**
     * Fake image generation.
     *
     * @param \Closure|array $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeImageGateway
     */
    public function fakeImages(Closure|array $responses = []): FakeImageGateway
    {
        return $this->fakeImageGateway = new FakeImageGateway($responses);
    }

    /**
     * Record an image generation.
     *
     * @param \Crustum\Ai\Prompts\ImagePrompt|\Crustum\Ai\Prompts\QueuedImagePrompt $prompt The prompt
     * @return $this
     */
    public function recordImageGeneration(ImagePrompt|QueuedImagePrompt $prompt)
    {
        if ($prompt instanceof QueuedImagePrompt) {
            $this->recordedQueuedImageGenerations[] = $prompt;
        } else {
            $this->recordedImageGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that an image was generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertImageGenerated(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedImageGenerations)->some(fn(ImagePrompt $prompt) => $callback($prompt)),
            'An expected image generation was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that an image was not generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertImageNotGenerated(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedImageGenerations)->some(fn(ImagePrompt $prompt) => $callback($prompt)),
            'An unexpected image generation was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no images were generated.
     *
     * @return $this
     */
    public function assertNoImagesGenerated()
    {
        PHPUnit::assertEmpty(
            $this->recordedImageGenerations,
            'Unexpected image generations were recorded.',
        );

        return $this;
    }

    /**
     * Assert that a queued image generation was recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertImageQueued(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedQueuedImageGenerations)->some(fn(QueuedImagePrompt $prompt) => $callback($prompt)),
            'An expected queued image generation was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a queued image generation was not recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertImageNotQueued(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedQueuedImageGenerations)->some(fn(QueuedImagePrompt $prompt) => $callback($prompt)),
            'An unexpected queued image generation was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no queued image generations were recorded.
     *
     * @return $this
     */
    public function assertNoImagesQueued()
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedImageGenerations,
            'Unexpected queued image generations were recorded.',
        );

        return $this;
    }

    /**
     * Determine if image generation is faked.
     *
     * @return bool
     */
    public function imagesAreFaked(): bool
    {
        return $this->fakeImageGateway !== null;
    }

    /**
     * Get the fake image gateway.
     */
    public function fakeImageGateway(): ?FakeImageGateway
    {
        return $this->fakeImageGateway;
    }
}
