<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * URL citation data.
 *
 * Represents a citation with a URL source, optionally including
 * position indices in the source text.
 */
class UrlCitation extends Citation implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param string $url Citation URL
     * @param string|null $title Citation title
     * @param int|null $startIndex Start position in text
     * @param int|null $endIndex End position in text
     */
    public function __construct(
        public string $url,
        ?string $title = null,
        public ?int $startIndex = null,
        public ?int $endIndex = null,
    ) {
        parent::__construct($title);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'start_index' => $this->startIndex,
            'end_index' => $this->endIndex,
        ];
    }

    /**
     * Get the JSON serializable representation.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
