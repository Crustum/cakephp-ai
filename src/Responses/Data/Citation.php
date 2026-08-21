<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

/**
 * Citation
 *
 * Abstract base class for citations returned in AI responses.
 */
abstract class Citation
{
    /**
     * Constructor
     *
     * @param string|null $title The citation title or label.
     */
    public function __construct(
        public ?string $title = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
