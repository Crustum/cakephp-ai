<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Closure;
use Crustum\Ai\Gateway\FakeClassificationGateway;
use Crustum\Ai\Prompts\ClassificationPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Interacts With Fake Classification Trait
 *
 * Provides methods for faking classification operations in tests.
 * Allows recording and asserting classification operations for testing purposes.
 */
trait InteractsWithFakeClassificationTrait
{
    /**
     * The fake classification gateway instance.
     */
    protected ?FakeClassificationGateway $fakeClassificationGateway = null;

    /**
     * All of the recorded classifications.
     *
     * @var array<\Crustum\Ai\Prompts\ClassificationPrompt>
     */
    protected array $recordedClassifications = [];

    /**
     * Fake classification operations.
     *
     * @param \Closure|array $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeClassificationGateway
     */
    public function fakeClassification(Closure|array $responses = []): FakeClassificationGateway
    {
        return $this->fakeClassificationGateway = new FakeClassificationGateway($responses);
    }

    /**
     * Record a classification.
     *
     * @param \Crustum\Ai\Prompts\ClassificationPrompt $prompt The prompt
     * @return $this
     */
    public function recordClassification(ClassificationPrompt $prompt)
    {
        $this->recordedClassifications[] = $prompt;

        return $this;
    }

    /**
     * Assert that a classification was performed matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertClassified(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedClassifications)->some(fn(ClassificationPrompt $prompt) => $callback($prompt)),
            'An expected classification was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a classification was not performed matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertNotClassified(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedClassifications)->some(fn(ClassificationPrompt $prompt) => $callback($prompt)),
            'An unexpected classification was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no classifications were performed.
     *
     * @return $this
     */
    public function assertNothingClassified()
    {
        PHPUnit::assertEmpty(
            $this->recordedClassifications,
            'Unexpected classifications were recorded.',
        );

        return $this;
    }

    /**
     * Determine if classification is faked.
     *
     * @return bool
     */
    public function classificationIsFaked(): bool
    {
        return $this->fakeClassificationGateway !== null;
    }

    /**
     * Get the fake classification gateway.
     */
    public function fakeClassificationGateway(): ?FakeClassificationGateway
    {
        return $this->fakeClassificationGateway;
    }
}
