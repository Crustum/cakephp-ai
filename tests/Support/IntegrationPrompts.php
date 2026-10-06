<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Loads configurable integration-test prompts from tests/Fixtures/integration-prompts.json.
 */
class IntegrationPrompts
{
    /**
     * Cached prompt definitions keyed by id.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected static ?array $prompts = null;

    /**
     * Get a prompt definition by id.
     *
     * @param string $id Prompt id
     * @return array{id: string, question: string, expected: array<int, string>, match: string, context?: string, prompt?: string, documents?: array<int, string>}
     */
    public static function get(string $id): array
    {
        $prompts = self::all();

        if (!isset($prompts[$id])) {
            throw new InvalidArgumentException(sprintf('Unknown integration prompt id [%s].', $id));
        }

        return $prompts[$id];
    }

    /**
     * Get the question text for a prompt id.
     *
     * @param string $id Prompt id
     * @return string
     */
    public static function question(string $id): string
    {
        return (string)self::get($id)['question'];
    }

    /**
     * Get the candidate documents for a reranking prompt id.
     *
     * @param string $id Prompt id
     * @return array<int, string>
     */
    public static function documents(string $id): array
    {
        return array_values(array_map(strval(...), self::get($id)['documents'] ?? []));
    }

    /**
     * Get optional prior context for a conversational prompt.
     *
     * @param string $id Prompt id
     * @return string|null
     */
    public static function context(string $id): ?string
    {
        $context = self::get($id)['context'] ?? null;

        return $context === null || $context === '' ? null : (string)$context;
    }

    /**
     * Get expected answer substrings / values for a prompt id.
     *
     * @param string $id Prompt id
     * @return array<int, string>
     */
    public static function expected(string $id): array
    {
        return array_values(array_map(strval(...), self::get($id)['expected'] ?? []));
    }

    /**
     * Render a prompt template, substituting `{question}` when present.
     *
     * @param string $id Prompt id
     * @return string
     */
    public static function prompt(string $id): string
    {
        $definition = self::get($id);
        $template = $definition['prompt'] ?? null;

        if (!is_string($template) || $template === '') {
            return (string)$definition['question'];
        }

        return str_replace('{question}', (string)$definition['question'], $template);
    }

    /**
     * Determine whether the given text satisfies the prompt expectations.
     *
     * @param string $id Prompt id
     * @param string $text Candidate answer text or structured value
     * @return bool
     */
    public static function matches(string $id, string $text): bool
    {
        $definition = self::get($id);
        $expected = self::expected($id);
        $match = (string)($definition['match'] ?? 'contains');

        if ($expected === []) {
            return $text !== '';
        }

        return match ($match) {
            'equals_ci' => array_any(
                $expected,
                fn(string $needle): bool => strtolower($text) === strtolower($needle),
            ),
            'contains_ci' => array_any(
                $expected,
                fn(string $needle): bool => str_contains(strtolower($text), strtolower($needle)),
            ),
            default => array_any(
                $expected,
                fn(string $needle): bool => str_contains($text, $needle),
            ),
        };
    }

    /**
     * Load and cache all prompt definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function all(): array
    {
        if (self::$prompts !== null) {
            return self::$prompts;
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'integration-prompts.json';

        if (!is_file($path)) {
            throw new RuntimeException('Integration prompts file not found: ' . $path);
        }

        $decoded = json_decode((string)file_get_contents($path), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid integration prompts JSON: ' . $path);
        }

        $prompts = [];

        foreach ($decoded as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $id = (string)($definition['id'] ?? $key);
            $definition['id'] = $id;
            $definition['question'] ??= '';
            $definition['expected'] ??= [];
            $definition['match'] ??= 'contains';
            $prompts[$id] = $definition;
        }

        return self::$prompts = $prompts;
    }
}
