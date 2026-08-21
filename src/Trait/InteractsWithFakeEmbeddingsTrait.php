<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Closure;
use Crustum\Ai\Gateway\FakeEmbeddingGateway;
use Crustum\Ai\Prompts\EmbeddingsPrompt;
use Crustum\Ai\Prompts\QueuedEmbeddingsPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Interacts With Fake Embeddings Trait
 *
 * Provides methods for faking embedding generation in tests.
 * Allows recording and asserting embeddings operations for testing purposes.
 */
trait InteractsWithFakeEmbeddingsTrait
{
    /**
     * The fake embedding gateway instance.
     */
    protected ?FakeEmbeddingGateway $fakeEmbeddingGateway = null;

    /**
     * All of the recorded embeddings generations.
     *
     * @var array<\Crustum\Ai\Prompts\EmbeddingsPrompt>
     */
    protected array $recordedEmbeddingsGenerations = [];

    /**
     * All of the recorded embeddings generations that were queued.
     *
     * @var array<\Crustum\Ai\Prompts\QueuedEmbeddingsPrompt>
     */
    protected array $recordedQueuedEmbeddingsGenerations = [];

    /**
     * Fake embeddings generation.
     *
     * @param \Closure|array $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeEmbeddingGateway
     */
    public function fakeEmbeddings(Closure|array $responses = []): FakeEmbeddingGateway
    {
        return $this->fakeEmbeddingGateway = new FakeEmbeddingGateway($responses);
    }

    /**
     * Record an embeddings generation.
     *
     * @param \Crustum\Ai\Prompts\EmbeddingsPrompt|\Crustum\Ai\Prompts\QueuedEmbeddingsPrompt $prompt The prompt
     * @return $this
     */
    public function recordEmbeddingsGeneration(EmbeddingsPrompt|QueuedEmbeddingsPrompt $prompt)
    {
        if ($prompt instanceof QueuedEmbeddingsPrompt) {
            $this->recordedQueuedEmbeddingsGenerations[] = $prompt;
        } else {
            $this->recordedEmbeddingsGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that embeddings were generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertEmbeddingsGenerated(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedEmbeddingsGenerations)->some(fn(EmbeddingsPrompt $prompt) => $callback($prompt)),
            'An expected embeddings generation was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that embeddings were not generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertEmbeddingsNotGenerated(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedEmbeddingsGenerations)->some(fn(EmbeddingsPrompt $prompt) => $callback($prompt)),
            'An unexpected embeddings generation was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no embeddings were generated.
     *
     * @return $this
     */
    public function assertNoEmbeddingsGenerated()
    {
        PHPUnit::assertEmpty(
            $this->recordedEmbeddingsGenerations,
            'Unexpected embeddings generations were recorded.',
        );

        return $this;
    }

    /**
     * Assert that a queued embeddings generation was recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertEmbeddingsQueued(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedQueuedEmbeddingsGenerations)->some(fn(QueuedEmbeddingsPrompt $prompt) => $callback($prompt)),
            'An expected queued embeddings generation was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a queued embeddings generation was not recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertEmbeddingsNotQueued(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedQueuedEmbeddingsGenerations)->some(fn(QueuedEmbeddingsPrompt $prompt) => $callback($prompt)),
            'An unexpected queued embeddings generation was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no queued embeddings generations were recorded.
     *
     * @return $this
     */
    public function assertNoEmbeddingsQueued()
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedEmbeddingsGenerations,
            'Unexpected queued embeddings generations were recorded.',
        );

        return $this;
    }

    /**
     * Determine if embeddings generation is faked.
     *
     * @return bool
     */
    public function embeddingsAreFaked(): bool
    {
        return $this->fakeEmbeddingGateway !== null;
    }

    /**
     * Get the fake embedding gateway.
     */
    public function fakeEmbeddingGateway(): ?FakeEmbeddingGateway
    {
        return $this->fakeEmbeddingGateway;
    }
}
