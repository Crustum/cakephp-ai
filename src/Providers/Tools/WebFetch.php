<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Tools;

/**
 * Web fetch tool.
 *
 * Enables fetching and reading web pages during AI conversations.
 */
class WebFetch extends ProviderTool
{
    /**
     * Constructor.
     *
     * @param int|null $maxSearches Maximum number of fetches allowed
     * @param array<string> $allowedDomains Domains allowed for fetching
     */
    public function __construct(
        public ?int $maxSearches = null,
        public array $allowedDomains = [],
    ) {
    }

    /**
     * Set the maximum number of fetches.
     *
     * @param int $max Maximum fetches
     */
    public function max(int $max): self
    {
        $this->maxSearches = $max;

        return $this;
    }

    /**
     * Set the allowed domains.
     *
     * @param array<string> $domains Allowed domains
     */
    public function allow(array $domains): self
    {
        $this->allowedDomains = $domains;

        return $this;
    }
}
