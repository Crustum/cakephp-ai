<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Cake\Core\Configure;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Trait for storable content.
 *
 * Provides file storage capabilities for AI-generated content
 * like images, audio, etc.
 */
trait StorableTrait
{
    /**
     * Cached random storage name.
     */
    protected ?string $randomStorageName = null;

    /**
     * Store content to filesystem.
     *
     * @param string $path Directory path
     * @param string|null $disk Disk name (unused in CakePHP, kept for compatibility)
     * @param array<string, mixed> $options Additional options
     * @return string|false File path on success, false on failure
     */
    public function store(string $path = '', ?string $disk = null, array $options = []): string|false
    {
        return $this->storeAs($path, $this->randomStorageName(), $disk, $options);
    }

    /**
     * Store content with public visibility.
     *
     * @param string $path Directory path
     * @param string|null $disk Disk name
     * @param array<string, mixed> $options Additional options
     * @return string|false File path on success, false on failure
     */
    public function storePublicly(string $path = '', ?string $disk = null, array $options = []): string|false
    {
        $options['visibility'] = 'public';

        return $this->storeAs($path, $this->randomStorageName(), $disk, $options);
    }

    /**
     * Store content with public visibility and specific name.
     *
     * @param string $path Directory path or filename
     * @param string|null $name Optional filename
     * @param string|null $disk Disk name
     * @param array<string, mixed> $options Additional options
     * @return string|false File path on success, false on failure
     */
    public function storePubliclyAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|false
    {
        if ($name === null) {
            [$path, $name] = ['', $path];
        }

        $options['visibility'] = 'public';

        return $this->storeAs($path, $name, $disk, $options);
    }

    /**
     * Store content with specific name.
     *
     * When $disk names a filesystem operator instance configured at
     * `Ai.filesystem.named.<disk>`, the write goes through that operator
     * (honoring the visibility option); otherwise the bytes are written to
     * the local path and visibility is applied best-effort via chmod.
     *
     * @param string $path Directory path or filename
     * @param string|null $name Optional filename
     * @param string|null $disk Disk name
     * @param array<string, mixed> $options Additional options
     * @return string|false File path on success, false on failure
     */
    public function storeAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|false
    {
        if ($name === null) {
            [$path, $name] = ['', $path];
        }

        if ($path === '') {
            $fullPath = $name;
        } else {
            $fullPath = rtrim($path, '/\\') . '/' . ltrim($name, '/\\');
        }

        $visibility = $options['visibility'] ?? null;
        $operator = $this->diskOperator($disk);

        if ($operator !== null) {
            try {
                $operator->write(
                    $fullPath,
                    $this->content(),
                    $visibility === null ? [] : ['visibility' => $visibility],
                );

                return $fullPath;
            } catch (FilesystemException) {
                return false;
            }
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents($fullPath, $this->content());

        if ($result === false) {
            return false;
        }

        if ($visibility === 'public') {
            chmod($fullPath, 0644);
        } elseif ($visibility === 'private') {
            chmod($fullPath, 0600);
        }

        return $fullPath;
    }

    /**
     * Resolve a named disk to a configured filesystem operator instance, if any.
     *
     * Only operator instances configured at `Ai.filesystem.named.<disk>` are
     * used here; array-root disks stay on the FilesystemRegistry path used by
     * file tools, keeping this trait free of the Files layer.
     *
     * @param string|null $disk Disk name
     * @return \League\Flysystem\FilesystemOperator|null
     */
    protected function diskOperator(?string $disk): ?FilesystemOperator
    {
        if ($disk === null) {
            return null;
        }

        $configured = Configure::read('Ai.filesystem.named.' . $disk);

        return $configured instanceof FilesystemOperator ? $configured : null;
    }

    /**
     * Get the content to store.
     *
     * Must be implemented by using class.
     *
     * @return string
     */
    abstract protected function content(): string;

    /**
     * Get a random storage filename.
     *
     * Must be implemented by using class.
     *
     * @return string
     */
    abstract protected function randomStorageName(): string;
}
