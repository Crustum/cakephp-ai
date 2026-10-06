<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Closure;
use Crustum\Ai\Gateway\FakeClassificationGateway;
use Crustum\Ai\PendingResponses\PendingClassification;

/**
 * Classification facade.
 *
 * Static interface for AI classification operations.
 */
class Classification
{
    /**
     * Create a new pending classification for the given state.
     *
     * @param array<string, mixed>|string $state State to classify
     * @return \Crustum\Ai\PendingResponses\PendingClassification
     * @throws \InvalidArgumentException if the state is blank
     */
    public static function of(string|array $state): PendingClassification
    {
        return new PendingClassification($state);
    }

    /**
     * Fake classification operations.
     *
     * @param \Closure|array<int, mixed> $responses Fake responses
     * @return \Crustum\Ai\Gateway\FakeClassificationGateway
     */
    public static function fake(Closure|array $responses = []): FakeClassificationGateway
    {
        return Ai::manager()->fakeClassification($responses);
    }

    /**
     * Assert that a classification was performed matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertClassified(Closure $callback): void
    {
        Ai::manager()->assertClassified($callback);
    }

    /**
     * Assert that a classification was not performed matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertNotClassified(Closure $callback): void
    {
        Ai::manager()->assertNotClassified($callback);
    }

    /**
     * Assert that no classifications were performed.
     *
     * @return void
     */
    public static function assertNothingClassified(): void
    {
        Ai::manager()->assertNothingClassified();
    }

    /**
     * Determine if classification is faked.
     *
     * @return bool
     */
    public static function isFaked(): bool
    {
        return Ai::manager()->classificationIsFaked();
    }
}
