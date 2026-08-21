<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Tools;

use InvalidArgumentException;

/**
 * Hosted tool search wrapper that defers tool definitions for on-demand loading.
 */
class ToolSearch extends ProviderTool
{
    /**
     * Constructor.
     *
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Deferred tools
     * @param string|null $strategy Search strategy (regex or bm25)
     */
    public function __construct(
        public readonly array $tools = [],
        public readonly ?string $strategy = null,
    ) {
        if ($strategy !== null && !in_array($strategy, ['regex', 'bm25'], true)) {
            throw new InvalidArgumentException(
                "Invalid tool search strategy [{$strategy}]. Supported strategies: regex, bm25.",
            );
        }
    }

    /**
     * Get a copy of the tool search with the given deferred tools.
     *
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Deferred tools
     */
    public function withTools(array $tools): self
    {
        $clone = new self($tools, $this->strategy);

        $clone->providerOptions = $this->providerOptions;

        return $clone;
    }

    /**
     * Count the given tools for step budgeting, expanding each ToolSearch into its deferred tools.
     *
     * @param array<mixed> $tools Available tools
     * @return int
     */
    public static function budget(array $tools): int
    {
        return array_sum(array_map(
            fn($tool): int => $tool instanceof self ? count($tool->tools) : 1,
            $tools,
        ));
    }
}
