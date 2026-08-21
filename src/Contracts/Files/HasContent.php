<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Files;

use Stringable;

/**
 * Interface for files that have retrievable content.
 *
 * This interface marks files that can provide their raw content as a string.
 * It extends Stringable to ensure files can be cast to strings.
 */
interface HasContent extends Stringable
{
    /**
     * Get the file's raw content.
     *
     * @return string The raw content of the file
     */
    public function content(): string;
}
