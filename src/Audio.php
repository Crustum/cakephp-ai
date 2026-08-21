<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Closure;
use Crustum\Ai\Gateway\FakeAudioGateway;
use Crustum\Ai\PendingResponses\PendingAudioGeneration;

/**
 * Audio facade.
 *
 * Static interface for AI audio generation operations.
 */
class Audio
{
    /**
     * Generate audio from the given text.
     *
     * @param string $text Text to synthesize
     * @return \Crustum\Ai\PendingResponses\PendingAudioGeneration
     * @throws \InvalidArgumentException if the given text is empty or whitespace-only
     */
    public static function of(string $text): PendingAudioGeneration
    {
        return new PendingAudioGeneration($text);
    }

    /**
     * Fake audio generation.
     *
     * @param \Closure|array<int, mixed> $responses Fake responses
     * @return \Crustum\Ai\Gateway\FakeAudioGateway
     */
    public static function fake(Closure|array $responses = []): FakeAudioGateway
    {
        return Ai::manager()->fakeAudio($responses);
    }

    /**
     * Assert that audio was generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::manager()->assertAudioGenerated($callback);
    }

    /**
     * Assert that audio was not generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::manager()->assertAudioNotGenerated($callback);
    }

    /**
     * Assert that no audio was generated.
     *
     * @return void
     */
    public static function assertNothingGenerated(): void
    {
        Ai::manager()->assertNoAudioGenerated();
    }

    /**
     * Assert that a queued audio generation was recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertQueued(Closure $callback): void
    {
        Ai::manager()->assertAudioQueued($callback);
    }

    /**
     * Assert that a queued audio generation was not recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertNotQueued(Closure $callback): void
    {
        Ai::manager()->assertAudioNotQueued($callback);
    }

    /**
     * Assert that no queued audio generations were recorded.
     *
     * @return void
     */
    public static function assertNothingQueued(): void
    {
        Ai::manager()->assertNoAudioQueued();
    }

    /**
     * Determine if audio generation is faked.
     *
     * @return bool
     */
    public static function isFaked(): bool
    {
        return Ai::manager()->audioIsFaked();
    }
}
