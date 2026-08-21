<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Files;

use Stringable;

/**
 * Interface for files that can be stored with an AI provider.
 *
 * This interface combines content, MIME type, and naming capabilities
 * to represent a complete storable file. It serves as a marker interface
 * for files ready to be uploaded or attached to AI requests.
 */
interface StorableFile extends HasContent, HasMimeType, HasName, Stringable
{
}
