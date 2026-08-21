<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Files;

/**
 * Interface for files that have a displayable name.
 *
 * This interface provides methods to get and set a human-readable name
 * for a file, which may differ from its storage identifier.
 */
interface HasName
{
    /**
     * Get the displayable name of the file.
     *
     * @return string|null The file name or null if not set
     */
    public function name(): ?string;

    /**
     * Set the displayable name of the file.
     *
     * Returns a new instance with the specified name.
     *
     * @param string|null $name The name to set, or null to clear it
     * @return $this A new instance with the updated name
     */
    public function as(?string $name): static;
}
