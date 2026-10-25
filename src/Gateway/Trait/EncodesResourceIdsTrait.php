<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use InvalidArgumentException;

/**
 * Encodes provider resource IDs as single URL path segments.
 */
trait EncodesResourceIdsTrait
{
    /**
     * Encode the given provider resource ID as a single URL path segment.
     *
     * @param string $id Resource ID
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function resourceId(string $id): string
    {
        if (in_array($id, ['', '.', '..'], true)) {
            throw new InvalidArgumentException("[{$id}] is not a valid provider resource ID.");
        }

        return rawurlencode($id);
    }
}
