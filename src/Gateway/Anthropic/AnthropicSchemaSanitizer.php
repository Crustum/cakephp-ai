<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Anthropic;

use Crustum\Ai\Utility\Value;

/**
 * Sanitizes JSON schemas for Anthropic native structured output.
 *
 * Strips keywords Anthropic rejects, folding each constraint into the node's
 * description so the model still honors the intent.
 */
class AnthropicSchemaSanitizer
{
    /**
     * Numeric constraints rejected by Anthropic native structured output.
     *
     * @var list<string>
     */
    protected const NUMERIC_KEYWORDS = [
        'minimum', 'exclusiveMinimum', 'maximum', 'exclusiveMaximum', 'multipleOf',
    ];

    /**
     * String constraints rejected by Anthropic native structured output.
     *
     * @var list<string>
     */
    protected const STRING_KEYWORDS = ['minLength', 'maxLength', 'pattern'];

    /**
     * Array constraints rejected beyond a "minItems" of 0 or 1.
     *
     * @var list<string>
     */
    protected const ARRAY_KEYWORDS = [
        'maxItems', 'uniqueItems', 'contains', 'minContains', 'maxContains',
        'prefixItems', 'unevaluatedItems',
    ];

    /**
     * Object constraints rejected beyond "properties", "required" and "additionalProperties".
     *
     * @var list<string>
     */
    protected const OBJECT_KEYWORDS = [
        'minProperties', 'maxProperties', 'patternProperties', 'propertyNames',
        'dependentRequired', 'dependentSchemas', 'unevaluatedProperties',
    ];

    /**
     * Subschema, annotation and identifier keywords outside the accepted subset.
     *
     * @var list<string>
     */
    protected const SCHEMA_KEYWORDS = [
        'not', 'if', 'then', 'else',
        'examples', 'deprecated', 'readOnly', 'writeOnly',
        'contentEncoding', 'contentMediaType', 'contentSchema',
        '$schema', '$id', '$anchor', '$comment', '$dynamicRef', '$dynamicAnchor', '$vocabulary',
    ];

    /**
     * Every keyword Anthropic native structured output rejects.
     *
     * @var list<string>
     */
    protected const REJECTED_KEYWORDS = [
        ...self::NUMERIC_KEYWORDS,
        ...self::STRING_KEYWORDS,
        ...self::ARRAY_KEYWORDS,
        ...self::OBJECT_KEYWORDS,
        ...self::SCHEMA_KEYWORDS,
    ];

    /**
     * String formats accepted by Anthropic native structured output.
     *
     * @var list<string>
     */
    protected const SUPPORTED_FORMATS = [
        'date-time', 'time', 'date', 'duration',
        'email', 'hostname', 'uri', 'ipv4', 'ipv6', 'uuid',
    ];

    /**
     * Strip the keywords Anthropic native structured output rejects.
     *
     * @param array<string, mixed> $schema Schema definition
     * @return array<string, mixed>
     */
    public static function sanitize(array $schema): array
    {
        return static::node($schema);
    }

    /**
     * Sanitize a single schema node, then recurse into its children.
     *
     * @param array<string, mixed> $schema Schema node
     * @return array<string, mixed>
     */
    protected static function node(array $schema): array
    {
        $schema = static::expandUnionType($schema);

        $notes = [];

        if (array_key_exists('minItems', $schema) && $schema['minItems'] > 1) {
            $notes[] = "Must contain at least {$schema['minItems']} items.";
            $schema['minItems'] = 1;
        }

        if (
            array_key_exists('format', $schema)
            && !in_array($schema['format'], static::SUPPORTED_FORMATS, true)
        ) {
            $notes[] = "Format: {$schema['format']}.";
            unset($schema['format']);
        }

        if (array_key_exists('enum', $schema) && !static::isSupportedEnum($schema['enum'])) {
            $notes[] = 'Must be one of: ' . json_encode($schema['enum']) . '.';
            unset($schema['enum']);
        }

        if (array_key_exists('additionalProperties', $schema)) {
            $schema['additionalProperties'] = false;
        }

        if (is_array($schema['oneOf'] ?? null)) {
            $schema['anyOf'] = array_merge($schema['anyOf'] ?? [], $schema['oneOf']);
            unset($schema['oneOf']);
        }

        foreach (static::REJECTED_KEYWORDS as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }

            $note = static::note($keyword, $schema[$keyword]);

            if (Value::filled($note)) {
                $notes[] = $note;
            }

            unset($schema[$keyword]);
        }

        return static::children(static::describe($schema, $notes));
    }

    /**
     * Recurse into every child schema of the given node.
     *
     * @param array<string, mixed> $schema Schema node
     * @return array<string, mixed>
     */
    protected static function children(array $schema): array
    {
        $sanitize = fn(mixed $child): mixed => is_array($child) ? static::node($child) : $child;

        foreach (['properties', '$defs', 'definitions', 'anyOf', 'allOf'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                $schema[$keyword] = array_map($sanitize, $schema[$keyword]);
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = array_is_list($schema['items'])
                ? array_map($sanitize, $schema['items'])
                : static::node($schema['items']);
        }

        return $schema;
    }

    /**
     * Rewrite a union "type" array into the anyOf form Anthropic documents.
     *
     * @param array<string, mixed> $schema Schema node
     * @return array<string, mixed>
     */
    protected static function expandUnionType(array $schema): array
    {
        if (!is_array($schema['type'] ?? null)) {
            return $schema;
        }

        $types = array_values(array_unique($schema['type']));

        if (count($types) === 1) {
            return [...$schema, 'type' => $types[0]];
        }

        $description = $schema['description'] ?? null;

        unset($schema['type'], $schema['description']);

        $branches = array_map(
            fn(string $type): array => $type === 'null' ? ['type' => 'null'] : ['type' => $type, ...$schema],
            $types,
        );

        return array_filter([
            'anyOf' => $branches,
            'description' => $description,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Append the given constraint notes to the node's description.
     *
     * @param array<string, mixed> $schema Schema node
     * @param array<int, string> $notes Constraint notes
     * @return array<string, mixed>
     */
    protected static function describe(array $schema, array $notes): array
    {
        if (Value::blank($notes)) {
            return $schema;
        }

        $description = trim((string)($schema['description'] ?? ''));

        if (Value::filled($description)) {
            $hasEnding = false;

            foreach (['.', '!', '?', ':', ';'] as $ending) {
                if (str_ends_with($description, $ending)) {
                    $hasEnding = true;

                    break;
                }
            }

            if (!$hasEnding) {
                $description .= '.';
            }
        }

        $schema['description'] = trim($description . ' ' . implode(' ', $notes));

        return $schema;
    }

    /**
     * Format a natural-language note for a constraint Anthropic rejects.
     *
     * @param string $keyword Rejected keyword
     * @param mixed $value Keyword value
     * @return string|null
     */
    protected static function note(string $keyword, mixed $value): ?string
    {
        if (in_array($keyword, static::NUMERIC_KEYWORDS, true) && !is_numeric($value)) {
            return null;
        }

        return match ($keyword) {
            'minimum' => "Must be at least {$value}.",
            'exclusiveMinimum' => "Must be greater than {$value}.",
            'maximum' => "Must be at most {$value}.",
            'exclusiveMaximum' => "Must be less than {$value}.",
            'multipleOf' => "Must be a multiple of {$value}.",
            'minLength' => 'Must be at least ' . static::characters($value) . '.',
            'maxLength' => 'Must be at most ' . static::characters($value) . '.',
            'pattern' => "Must match the pattern {$value}.",
            'maxItems' => "Must contain at most {$value} items.",
            'minContains' => "Must contain at least {$value} matching items.",
            'maxContains' => "Must contain at most {$value} matching items.",
            'uniqueItems' => $value === true ? 'All items must be unique.' : null,
            'minProperties' => "Must have at least {$value} properties.",
            'maxProperties' => "Must have at most {$value} properties.",
            default => null,
        };
    }

    /**
     * Pluralize a character count for a string-length note.
     *
     * @param mixed $value Character count
     * @return string
     */
    protected static function characters(mixed $value): string
    {
        return $value . ' ' . ((int)$value === 1 ? 'character' : 'characters');
    }

    /**
     * Determine if every member of the given enum is a type Anthropic accepts.
     *
     * @param mixed $enum Enum values
     * @return bool
     */
    protected static function isSupportedEnum(mixed $enum): bool
    {
        if (!is_array($enum)) {
            return false;
        }

        foreach ($enum as $member) {
            if (!is_scalar($member) && $member !== null) {
                return false;
            }
        }

        return true;
    }
}
