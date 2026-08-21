<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * Meta
 *
 * Metadata about an AI response including provider information and citations.
 */
class Meta implements JsonSerializable
{
    /**
     * @var array<int, \Crustum\Ai\Responses\Data\Citation> Citations indexed for array access.
     */
    public array $citations;

    /**
     * Constructor
     *
     * @param string|null $provider The provider name.
     * @param string|null $model The model name.
     * @param array<int, \Crustum\Ai\Responses\Data\Citation>|null $citations Citations.
     */
    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        ?array $citations = null,
    ) {
        $this->citations = $citations ?? [];
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed> The array representation.
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'citations' => array_map(
                static fn(Citation $citation): array => $citation->toArray(),
                $this->citations,
            ),
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, mixed> The JSON serializable data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
