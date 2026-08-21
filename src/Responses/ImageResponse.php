<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\CollectionInterface;
use Countable;
use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use RuntimeException;
use Stringable;

/**
 * Image Response
 *
 * Represents a response containing generated images.
 */
class ImageResponse implements Countable, Stringable
{
    public ImageList $images;

    /**
     * Constructor
     *
     * @param \Crustum\Ai\Responses\ImageList|\Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\GeneratedImage>|array<int, \Crustum\Ai\Responses\Data\GeneratedImage> $images The generated images
     * @param \Crustum\Ai\Responses\Data\Usage $usage Token usage information
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata about the response
     */
    public function __construct(
        ImageList|array|CollectionInterface $images,
        public Usage $usage,
        public Meta $meta,
    ) {
        $this->images = match (true) {
            $images instanceof ImageList => $images,
            $images instanceof CollectionInterface => new ImageList($images->toList()),
            default => new ImageList($images),
        };
    }

    /**
     * Get the first image in the response.
     *
     * @return \Crustum\Ai\Responses\Data\GeneratedImage
     * @throws \RuntimeException
     */
    public function firstImage(): GeneratedImage
    {
        if ($this->images->isEmpty()) {
            throw new RuntimeException('The image response does not contain any images.');
        }

        return $this->images->first();
    }

    /**
     * Store the image on a filesystem disk.
     *
     * @param string $path The path to store the image
     * @param string|null $disk The disk to use
     * @param array<string, mixed> $options Additional options
     */
    public function store(string $path = '', ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstImage()->store($path, $disk, $options);
    }

    /**
     * Store the image on a filesystem disk with public visibility.
     *
     * @param string $path The path to store the image
     * @param string|null $disk The disk to use
     * @param array<string, mixed> $options Additional options
     */
    public function storePublicly(string $path = '', ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstImage()->storePublicly($path, $disk, $options);
    }

    /**
     * Store the image on a filesystem disk with public visibility.
     *
     * @param string $path The path to store the image
     * @param string|null $name The filename
     * @param string|null $disk The disk to use
     * @param array<string, mixed> $options Additional options
     */
    public function storePubliclyAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstImage()->storePubliclyAs($path, $name, $disk, $options);
    }

    /**
     * Store the image on a filesystem disk.
     *
     * @param string $path The path to store the image
     * @param string|null $name The filename
     * @param string|null $disk The disk to use
     * @param array<string, mixed> $options Additional options
     */
    public function storeAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|bool
    {
        return $this->firstImage()->storeAs($path, $name, $disk, $options);
    }

    /**
     * Get an <img> tag for the image.
     *
     * @param string $alt Alternative text for the image
     * @return string
     */
    public function toHtml(string $alt = ''): string
    {
        $image = $this->firstImage();

        return sprintf(
            '<img src="data:%s;base64,%s" alt="%s" />',
            $image->mime(),
            $image->image,
            h($alt),
        );
    }

    /**
     * Get the number of images that were generated.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->images);
    }

    /**
     * Get the raw string content of the image.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string)$this->firstImage();
    }
}
