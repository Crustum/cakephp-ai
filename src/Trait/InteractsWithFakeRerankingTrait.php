<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Closure;
use Crustum\Ai\Gateway\FakeRerankingGateway;
use Crustum\Ai\Prompts\RerankingPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Interacts With Fake Reranking Trait
 *
 * Provides methods for faking reranking operations in tests.
 * Allows recording and asserting reranking operations for testing purposes.
 */
trait InteractsWithFakeRerankingTrait
{
    /**
     * The fake reranking gateway instance.
     */
    protected ?FakeRerankingGateway $fakeRerankingGateway = null;

    /**
     * All of the recorded rerankings.
     *
     * @var array<\Crustum\Ai\Prompts\RerankingPrompt>
     */
    protected array $recordedRerankings = [];

    /**
     * Fake reranking operations.
     *
     * @param \Closure|array $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeRerankingGateway
     */
    public function fakeReranking(Closure|array $responses = []): FakeRerankingGateway
    {
        return $this->fakeRerankingGateway = new FakeRerankingGateway($responses);
    }

    /**
     * Record a reranking.
     *
     * @param \Crustum\Ai\Prompts\RerankingPrompt $prompt The prompt
     * @return $this
     */
    public function recordReranking(RerankingPrompt $prompt)
    {
        $this->recordedRerankings[] = $prompt;

        return $this;
    }

    /**
     * Assert that a reranking was performed matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertReranked(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedRerankings)->some(fn(RerankingPrompt $prompt) => $callback($prompt)),
            'An expected reranking was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a reranking was not performed matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertNotReranked(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedRerankings)->some(fn(RerankingPrompt $prompt) => $callback($prompt)),
            'An unexpected reranking was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no rerankings were performed.
     *
     * @return $this
     */
    public function assertNothingReranked()
    {
        PHPUnit::assertEmpty(
            $this->recordedRerankings,
            'Unexpected rerankings were recorded.',
        );

        return $this;
    }

    /**
     * Determine if reranking is faked.
     *
     * @return bool
     */
    public function rerankingIsFaked(): bool
    {
        return $this->fakeRerankingGateway !== null;
    }

    /**
     * Get the fake reranking gateway.
     */
    public function fakeRerankingGateway(): ?FakeRerankingGateway
    {
        return $this->fakeRerankingGateway;
    }
}
