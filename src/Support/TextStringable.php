<?php
declare(strict_types=1);

namespace Crustum\Ai\Support;

use BackedEnum;
use Closure;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Text;
use Stringable;

/**
 * Fluent string wrapper for AI-backed text helpers.
 */
class TextStringable implements Stringable
{
    /**
     * @param string $value Wrapped string value
     */
    public function __construct(protected string $value)
    {
    }

    /**
     * Summarize the wrapped text.
     *
     * @param int $sentences Maximum number of sentences in the summary
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param string|null $model Model name
     * @param int|null $timeout Request timeout in seconds
     * @return string
     */
    public function summarize(
        int $sentences = 3,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): string {
        return Text::summarize($this->value, $sentences, $provider, $model, $timeout);
    }

    /**
     * Decide a boolean question about the wrapped text.
     *
     * @param string $question Question to decide
     * @param array{true?: string, false?: string} $criteria Descriptions of what true and false mean
     * @param float $threshold Probability threshold for a true decision
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param string|null $model Model name
     * @param int|null $timeout Request timeout in seconds
     * @return bool
     */
    public function decide(
        string $question,
        array $criteria = [],
        float $threshold = 0.5,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): bool {
        return Text::decide($this->value, $question, $criteria, $threshold, $provider, $model, $timeout);
    }

    /**
     * Generate the embedding vector for the wrapped text.
     *
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param int|null $dimensions Embedding dimensions
     * @param string|null $model Model name
     * @param int|bool|null $cache Cache(seconds), true for defaults, false to disable
     * @param int|null $timeout Request timeout in seconds
     * @param \Closure|array<string, mixed> $providerOptions Provider-specific options
     * @return array<int, float>
     */
    public function toEmbeddings(
        Lab|array|string|null $provider = null,
        ?int $dimensions = null,
        ?string $model = null,
        bool|int|null $cache = null,
        ?int $timeout = null,
        array|Closure $providerOptions = [],
    ): array {
        return Text::toEmbeddings(
            $this->value,
            $provider,
            $dimensions,
            $model,
            $cache,
            $timeout,
            $providerOptions,
        );
    }

    /**
     * Generate audio for the wrapped text.
     *
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param \BackedEnum|string|null $voice Voice identifier
     * @param string|null $instructions Voice instructions
     * @param string|null $model Model name
     * @param int|null $timeout Request timeout in seconds
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    public function toAudio(
        Lab|array|string|null $provider = null,
        BackedEnum|string|null $voice = null,
        ?string $instructions = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AudioResponse {
        return Text::toAudio($this->value, $provider, $voice, $instructions, $model, $timeout);
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
