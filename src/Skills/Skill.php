<?php
declare(strict_types=1);

namespace Crustum\Ai\Skills;

use Stringable;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * An agent skill loaded from a directory containing a SKILL.md file.
 */
class Skill
{
    /**
     * Create a new skill instance.
     *
     * @param string $name Skill name
     * @param \Stringable|string $description Skill description
     * @param \Stringable|string $instructions Skill instructions
     * @param array<string, \Stringable|string> $files Files bundled with the skill
     * @param string|null $path Skill directory path
     */
    public function __construct(
        public readonly string $name,
        public readonly Stringable|string $description,
        public readonly Stringable|string $instructions,
        public readonly array $files = [],
        public readonly ?string $path = null,
    ) {
    }

    /**
     * Create a skill from a directory containing a SKILL.md file.
     *
     * @param string $directory Skill directory
     * @return self|null
     */
    public static function fromDirectory(string $directory): ?self
    {
        $directory = rtrim($directory, '/\\');

        $file = $directory . '/SKILL.md';

        if (!is_file($file)) {
            return null;
        }

        if (!preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', (string)file_get_contents($file), $matches)) {
            return null;
        }

        try {
            $frontmatter = (array)Yaml::parse($matches[1]);
        } catch (ParseException $parseException) {
            $parseException->setParsedFile($file);

            throw $parseException;
        }

        $description = $frontmatter['description'] ?? null;

        if (!is_string($description) || blank($description)) {
            return null;
        }

        $name = $frontmatter['name'] ?? null;

        return new self(
            name: is_scalar($name) && !blank($name) ? (string)$name : basename($directory),
            description: $description,
            instructions: trim($matches[2]),
            path: $directory,
        );
    }
}
