<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Crustum\Ai\Agents\SummarizeAgent;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Support\TextStringable;

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
