<?php
declare(strict_types=1);

namespace Crustum\Ai\Support;

use Crustum\Ai\Enums\Lab;
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
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
