<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Closure;
use Crustum\Ai\Gateway\FakeImageGateway;
use Crustum\Ai\PendingResponses\PendingImageGeneration;

/**
 * Image facade.
 *
 * Static interface for AI image generation operations.
 */
class Image
{
    /**
     * Generate an image.
     *
     * @param string $prompt Image prompt
     * @return \Crustum\Ai\PendingResponses\PendingImageGeneration
     * @throws \InvalidArgumentException if the given prompt is empty or whitespace-only
     */
    public static function of(string $prompt): PendingImageGeneration
    {
        return new PendingImageGeneration($prompt);
    }

    /**
     * Fake image generation.
     *
     * @param \Closure|array<int, mixed> $responses Fake responses
     * @return \Crustum\Ai\Gateway\FakeImageGateway
     */
    public static function fake(Closure|array $responses = []): FakeImageGateway
    {
        return Ai::manager()->fakeImages($responses);
    }

    /**
     * Assert that an image was generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::manager()->assertImageGenerated($callback);
    }

    /**
     * Assert that an image was not generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::manager()->assertImageNotGenerated($callback);
    }

    /**
     * Assert that no images were generated.
     *
     * @return void
     */
    public static function assertNothingGenerated(): void
    {
        Ai::manager()->assertNoImagesGenerated();
    }

    /**
     * Assert that a queued image generation was recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertQueued(Closure $callback): void
    {
        Ai::manager()->assertImageQueued($callback);
    }

    /**
     * Assert that a queued image generation was not recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertNotQueued(Closure $callback): void
    {
        Ai::manager()->assertImageNotQueued($callback);
    }

    /**
     * Assert that no queued image generations were recorded.
     *
     * @return void
     */
    public static function assertNothingQueued(): void
    {
        Ai::manager()->assertNoImagesQueued();
    }

    /**
     * Determine if image generation is faked.
     *
     * @return bool
     */
    public static function isFaked(): bool
    {
        return Ai::manager()->imagesAreFaked();
    }
}
