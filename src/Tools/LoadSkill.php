<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use Cake\Collection\CollectionInterface;
use Cake\Log\Log;
use Closure;
use Crustum\Ai\Contracts\HasSkills;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Skills\Skill;
use Crustum\JsonSchema\Contracts\JsonSchema;
use FilesystemIterator;
use Generator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Stringable;
use Traversable;

/**
 * Loads agent skill instructions and files bundled alongside them.
 */
class LoadSkill implements Tool
{
    /**
     * The maximum number of bytes that may be read from a bundled file.
     */
    protected const MAX_BYTES = 256 * 1024;

    /**
     * The resolved skills, keyed by name.
     *
     * @var \Cake\Collection\CollectionInterface<string, \Crustum\Ai\Skills\Skill>|null
     */
    protected ?CollectionInterface $skills = null;

    /**
     * Create a new skill loading tool instance.
     *
     * @param array<int, \Closure|\Crustum\Ai\Skills\Skill|string> $sources Skill sources
     */
    public function __construct(protected array $sources = [])
    {
    }

    /**
     * Add the agent's skills to the tools, merging them into a skill loading tool the agent already declares.
     *
     * @param array<int, mixed> $tools Declared tools
     * @param \Crustum\Ai\Contracts\HasSkills $agent Agent instance
     * @return array<int, mixed>
     */
    public static function mergeInto(array $tools, HasSkills $agent): array
    {
        if ([...$agent->skills()] === []) {
            return $tools;
        }

        foreach ($tools as $index => $tool) {
            if ($tool instanceof self) {
                Log::warning('Agent [' . $agent::class . '] declares both skills and a LoadSkill tool; its skills were merged into that tool.');

                $tools[$index] = $tool->withSkills([...$agent->skills()]);

                return $tools;
            }
        }

        return [...$tools, new self([...$agent->skills()])];
    }

    /**
     * Get the description of the tool's purpose.
     *
     * @return \Stringable|string
     */
    public function description(): Stringable|string
    {
        /** @var \Cake\Collection\CollectionInterface<int, string> $lines */
        $lines = $this->skills()
            ->map(fn(Skill $skill): string => "- {$skill->name}: " . self::squish((string)$skill->description));

        return $lines
            ->prependItem("Load a skill's instructions before performing a task matching the skill's description. Pass a path to read one of the skill's bundled files instead.\n\nAvailable skills:")
            ->implode("\n");
    }

    /**
     * Execute the tool.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request
     * @return \Stringable|string
     */
    public function handle(Request $request): Stringable|string
    {
        $name = $request->string('name');

        /** @var array<string, \Crustum\Ai\Skills\Skill> $skills */
        $skills = $this->skills()->toArray();

        $skill = $skills[$name] ?? null;

        if (!$skill instanceof Skill) {
            return "Skill [{$name}] does not exist.";
        }

        $path = $request->string('path');

        return $path !== ''
            ? $this->resource($skill, $path)
            : $this->instructions($skill);
    }

    /**
     * Get the tool's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema Schema builder
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $name = $schema->string()->description('The name of the skill to load.')->required();

        /** @var array<int, string> $names */
        $names = $this->skills()->extract(fn(Skill $skill): string => $skill->name)->toList();

        // An empty enum matches no value at all and is rejected outright under strict schemas...
        if ($names !== []) {
            $name->enum($names);
        }

        return [
            'name' => $name,
            'path' => $schema->string()
                ->description("A bundled file path listed by the skill, read instead of the skill's instructions."),
        ];
    }

    /**
     * Get the skill's instructions and the files bundled alongside them.
     *
     * @param \Crustum\Ai\Skills\Skill $skill Skill instance
     * @return string
     */
    protected function instructions(Skill $skill): string
    {
        $files = implode("\n", $this->resources($skill));

        $resources = $files !== ''
            ? "\n\n<skill_resources>\n" . $files . "\n</skill_resources>\nRead one of these files by calling this tool again with the skill name and the file's path."
            : '';

        return "<skill_content name=\"{$skill->name}\">\n{$skill->instructions}{$resources}\n</skill_content>";
    }

    /**
     * Read a file bundled with the given skill.
     *
     * @param \Crustum\Ai\Skills\Skill $skill Skill instance
     * @param string $path Bundled file path
     * @return string
     */
    protected function resource(Skill $skill, string $path): string
    {
        $contents = array_key_exists($path, $skill->files)
            ? (string)$skill->files[$path]
            : $this->read($skill, $path);

        if ($contents === null) {
            return "File [{$path}] is not bundled with skill [{$skill->name}].";
        }

        if (strlen($contents) > static::MAX_BYTES) {
            return "File [{$path}] is too large to read inline.";
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            return "File [{$path}] appears to be binary and cannot be read as text.";
        }

        return $contents;
    }

    /**
     * Read a file from the skill's directory, reading one byte past the limit so oversized files are detected.
     *
     * @param \Crustum\Ai\Skills\Skill $skill Skill instance
     * @param string $path Bundled file path
     * @return string|null
     */
    protected function read(Skill $skill, string $path): ?string
    {
        $directory = $skill->path === null ? false : realpath($skill->path);
        $file = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . $path);

        if ($file === false || !is_file($file) || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return (string)file_get_contents($file, length: static::MAX_BYTES + 1);
    }

    /**
     * Get the paths of the files bundled with the given skill.
     *
     * @param \Crustum\Ai\Skills\Skill $skill Skill instance
     * @return array<int, string>
     */
    protected function resources(Skill $skill): array
    {
        if ($skill->files !== []) {
            return array_keys($skill->files);
        }

        if ($skill->path === null || !is_dir($skill->path)) {
            return [];
        }

        $base = rtrim($skill->path, '/\\');

        /** @var \Cake\Collection\CollectionInterface<int, string> $paths */
        $paths = collection($this->walk($base))
            ->filter(fn(SplFileInfo $file): bool => $file->isFile())
            ->map(fn(SplFileInfo $file): string => str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1)))
            ->reject(fn(string $path): bool => $path === 'SKILL.md')
            ->sortBy(fn(string $path): string => $path, SORT_ASC, SORT_STRING);

        return $paths->toList();
    }

    /**
     * Resolve every skill available to the tool, keyed by name.
     *
     * @return \Cake\Collection\CollectionInterface<string, \Crustum\Ai\Skills\Skill>
     */
    protected function skills(): CollectionInterface
    {
        return $this->skills ??= collection($this->orderedSources())
            ->unfold(fn(Closure|Skill|string $source): iterable => match (true) {
                $source instanceof Skill => [$source],
                $source instanceof Closure => $this->closureSkills($source),
                default => $this->discover($source),
            })
            ->sortBy(fn(Skill $skill): string => $skill->name, SORT_ASC, SORT_STRING)
            ->indexBy(fn(Skill $skill): string => $skill->name);
    }

    /**
     * Resolve the skills returned by the given closure source.
     *
     * @param \Closure $source Skill source
     * @return array<int, mixed>
     * @throws \InvalidArgumentException if the closure does not return an iterable
     */
    protected function closureSkills(Closure $source): array
    {
        $resolved = $source();

        if ($resolved instanceof CollectionInterface) {
            return $resolved->toList();
        }

        if ($resolved instanceof Traversable) {
            return iterator_to_array($resolved, false);
        }

        if (is_array($resolved)) {
            return array_values($resolved);
        }

        throw new InvalidArgumentException('Skill closure sources must return an iterable.');
    }

    /**
     * Discover the skills stored in the given directory.
     *
     * @param string $directory Skills directory
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Skills\Skill>
     */
    protected function discover(string $directory): CollectionInterface
    {
        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Skills\Skill|null> $skills */
        $skills = collection(glob(rtrim($directory, '/\\') . '/*/SKILL.md') ?: [])
            ->map(fn(string $file): ?Skill => Skill::fromDirectory(dirname($file)));

        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Skills\Skill> $filtered */
        $filtered = $skills->filter();

        return $filtered;
    }

    /**
     * Get the skill sources in resolution order.
     *
     * Sources are reversed so the earliest source wins name collisions when
     * the resolved skills are indexed by name.
     *
     * @return array<int, \Closure|\Crustum\Ai\Skills\Skill|string>
     */
    protected function orderedSources(): array
    {
        if ($this->sources !== []) {
            return array_reverse($this->sources);
        }

        $default = $this->defaultDirectory();

        return $default === null ? [] : [$default];
    }

    /**
     * Get the default directory skills are discovered from.
     *
     * @return string|null
     */
    protected function defaultDirectory(): ?string
    {
        if (defined('RESOURCES')) {
            return RESOURCES . 'skills';
        }

        return defined('ROOT') ? ROOT . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'skills' : null;
    }

    /**
     * Get a copy of the tool that also loads the given skills.
     *
     * @param iterable<\Closure|\Crustum\Ai\Skills\Skill|string> $sources Skill sources
     * @return static
     */
    protected function withSkills(iterable $sources): static
    {
        $tool = clone $this;

        $default = $this->defaultDirectory();
        $base = $this->sources !== [] ? $this->sources : ($default === null ? [] : [$default]);

        $tool->sources = [...$base, ...$sources];
        $tool->skills = null;

        return $tool;
    }

    /**
     * Iterate over every file below the given directory.
     *
     * @param string $directory Directory path
     * @return \Generator<int, \SplFileInfo>
     */
    protected function walk(string $directory): Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo) {
                yield $file;
            }
        }
    }

    /**
     * Collapse every whitespace run in the given value into a single space.
     *
     * @param string $value Value to squish
     * @return string
     */
    protected static function squish(string $value): string
    {
        return (string)preg_replace('/\s+/', ' ', trim($value));
    }
}
