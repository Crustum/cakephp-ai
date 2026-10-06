<?php
declare(strict_types=1);

namespace Crustum\Ai;

use BackedEnum;
use Closure;
use Crustum\Ai\Agents\SummarizeAgent;
use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\Data\BooleanAnswer;
use Crustum\Ai\Support\TextStringable;
use UnexpectedValueException;

/**
 * Text facade for AI-backed string helpers.
 */
class Text
{
    /**
     * Summarize the given text.
     *
     * @param string $value Text to summarize
     * @param int $sentences Maximum number of sentences in the summary
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param string|null $model Model name
     * @param int|null $timeout Request timeout in seconds
     * @return string
     */
    public static function summarize(
        string $value,
        int $sentences = 3,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): string {
        return (new SummarizeAgent($sentences))
            ->prompt($value, provider: $provider, model: $model, timeout: $timeout)
            ->text;
    }

    /**
     * Decide a boolean question about the given text.
     *
     * @param string $value Text to classify
     * @param string $question Question to decide
     * @param array{true?: string, false?: string} $criteria Descriptions of what true and false mean
     * @param float $threshold Probability threshold for a true decision
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param string|null $model Model name
     * @param int|null $timeout Request timeout in seconds
     * @return bool
     */
    public static function decide(
        string $value,
        string $question,
        array $criteria = [],
        float $threshold = 0.5,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): bool {
        $request = Classification::of($value)
            ->question('decision', new Boolean($question, $criteria === [] ? null : $criteria));

        if ($timeout !== null) {
            $request->timeout($timeout);
        }

        $answer = $request->classify($provider, $model)->answer('decision');

        if (!$answer instanceof BooleanAnswer) {
            throw new UnexpectedValueException('Decision classification must return a boolean answer.');
        }

        return $answer->isTrue($threshold);
    }

    /**
     * Generate the embedding vector for the given text.
     *
     * @param string $value Text to embed
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param int|null $dimensions Embedding dimensions
     * @param string|null $model Model name
     * @param int|bool|null $cache Cache(seconds), true for defaults, false to disable
     * @param int|null $timeout Request timeout in seconds
     * @param \Closure|array<string, mixed> $providerOptions Provider-specific options
     * @return array<int, float>
     */
    public static function toEmbeddings(
        string $value,
        Lab|array|string|null $provider = null,
        ?int $dimensions = null,
        ?string $model = null,
        bool|int|null $cache = null,
        ?int $timeout = null,
        array|Closure $providerOptions = [],
    ): array {
        $request = Embeddings::for([$value]);

        if ($dimensions !== null) {
            $request->dimensions($dimensions);
        }

        if ($cache === false) {
            $request->cache(0);
        } elseif ($cache !== null) {
            $request->cache(is_int($cache) ? $cache : null);
        }

        if ($timeout !== null) {
            $request->timeout($timeout);
        }

        if ($providerOptions !== []) {
            $request->withProviderOptions($providerOptions);
        }

        return $request->generate(provider: $provider, model: $model)->embeddings[0];
    }

    /**
     * Generate audio for the given text.
     *
     * @param string $value Text to synthesize
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param \BackedEnum|string|null $voice Voice identifier
     * @param string|null $instructions Voice instructions
     * @param string|null $model Model name
     * @param int|null $timeout Request timeout in seconds
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    public static function toAudio(
        string $value,
        Lab|array|string|null $provider = null,
        BackedEnum|string|null $voice = null,
        ?string $instructions = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AudioResponse {
        $request = Audio::of($value);

        if ($voice !== null) {
            $request->voice($voice);
        }

        if ($instructions !== null) {
            $request->instructions($instructions);
        }

        if ($timeout !== null) {
            $request->timeout($timeout);
        }

        return $request->generate(provider: $provider, model: $model);
    }

    /**
     * Wrap a string for fluent text helpers.
     *
     * @param string $value String value
     * @return \Crustum\Ai\Support\TextStringable
     */
    public static function of(string $value): TextStringable
    {
        return new TextStringable($value);
    }
}
