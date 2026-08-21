<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

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
     * Store content with public visibility (CakePHP doesn't have visibility, but kept for API compatibility).
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
     * @param string $path Directory path or filename
     * @param string|null $name Optional filename
     * @param string|null $disk Disk name (unused in CakePHP)
     * @param array<string, mixed> $options Additional options
     * @return string|false File path on success, false on failure
     */
    public function storeAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|false
    {
        if ($name === null) {
            $name = $path;
            $path = '';
        }

        $fullPath = $path === '' ? $name : $path . '/' . $name;
        $fullPath = rtrim(preg_replace('#/+#', '/', $fullPath), '/');

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents($fullPath, $this->content());

        return $result !== false ? $fullPath : false;
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
