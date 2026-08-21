<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Tools;

/**
 * Web search tool.
 *
 * Enables web search capabilities during AI conversations with optional location refinement.
 */
class WebSearch extends ProviderTool
{
    public ?string $city = null;

    public ?string $region = null;

    public ?string $country = null;

    /**
     * Constructor.
     *
     * @param int|null $maxSearches Maximum number of searches allowed
     * @param array<string> $allowedDomains Domains allowed in search results
     */
    public function __construct(
        public ?int $maxSearches = null,
        public array $allowedDomains = [],
    ) {
    }

    /**
     * Set the maximum number of searches.
     *
     * @param int $max Maximum searches
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

    /**
     * Set the user's location to refine search results based on the given location.
     *
     * @param string|null $city City name
     * @param string|null $region Region or state
     * @param string|null $country Country name
     */
    public function location(?string $city = null, ?string $region = null, ?string $country = null): self
    {
        $this->city = $city;
        $this->region = $region;
        $this->country = $country;

        return $this;
    }

    /**
     * Determine if the web search uses the user's location.
     *
     * @return bool
     */
    public function hasLocation(): bool
    {
        return $this->city !== null ||
            $this->region !== null ||
            $this->country !== null;
    }
}
