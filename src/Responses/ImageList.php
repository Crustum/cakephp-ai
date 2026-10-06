<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use ArrayObject;
use Crustum\Ai\Responses\Data\GeneratedImage;
use RuntimeException;

/**
 * Indexed list of generated images with array-style access.
 *
 * @extends \ArrayObject<int, \Crustum\Ai\Responses\Data\GeneratedImage>
 */
class ImageList extends ArrayObject
{
    /**
     * @param array<int, \Crustum\Ai\Responses\Data\GeneratedImage> $images Generated images
     */
    public function __construct(array $images = [])
    {
        parent::__construct($images);
    }

    /**
     * Determine if the list is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Get the first image in the list.
     *
     * @return \Crustum\Ai\Responses\Data\GeneratedImage
     */
    public function first(): GeneratedImage
    {
        if ($this->isEmpty()) {
            throw new RuntimeException('The image response does not contain any images.');
        }

        return $this[0];
    }

    /**
     * Get the last image in the list.
     *
     * @return \Crustum\Ai\Responses\Data\GeneratedImage
     */
    public function last(): GeneratedImage
    {
        if ($this->isEmpty()) {
            throw new RuntimeException('The image response does not contain any images.');
        }

        return $this[$this->count() - 1];
    }
}
