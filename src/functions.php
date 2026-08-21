<?php
declare(strict_types=1);

/**
 * Global helper functions for AI plugin
 */

use Crustum\Ai\AnonymousAgent;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Gateway\Fake\SchemaDataGenerator;
use Crustum\Ai\Pipeline\Pipeline;
use Crustum\Ai\StructuredAnonymousAgent;
use Crustum\Ai\Utility\Identifier;
use Crustum\Ai\Utility\Reflection;
use Crustum\Ai\Utility\Value;
use Crustum\JsonSchema\Types\Type;

if (!function_exists('agent')) {
    /**
     * Get an ad-hoc agent instance.
     *
     * @param string $instructions System instructions
     * @param iterable<int, mixed> $messages Initial messages
     * @param iterable<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     * @param \Closure|null $schema Structured output schema builder
     * @return \Crustum\Ai\Contracts\Agent
     */
    function agent(
        string $instructions = '',
        iterable $messages = [],
        iterable $tools = [],
        ?Closure $schema = null,
    ): Agent {
        return $schema instanceof Closure
            ? new StructuredAnonymousAgent($instructions, $messages, $tools, $schema)
            : new AnonymousAgent($instructions, $messages, $tools);
    }
}

if (!function_exists('pipeline')) {
    /**
     * Get a new pipeline instance.
     *
     * @return \Crustum\Ai\Pipeline\Pipeline
     */
    function pipeline(): Pipeline
    {
        return new Pipeline();
    }
}

if (!function_exists('tap')) {
    /**
     * Call the given callback with the given value then return the value.
     *
     * @template TValue
     * @param TValue $value Value to tap
     * @param \Closure|null $callback Optional callback
     * @return TValue
     */
    function tap(mixed $value, ?Closure $callback = null): mixed
    {
        if ($callback instanceof Closure) {
            $callback($value);
        }

        return $value;
    }
}

if (!function_exists('ulid')) {
    /**
     * Generate a new ULID.
     *
     * @return string
     */
    function ulid(): string
    {
        return Identifier::ulid();
    }
}

if (!function_exists('class_uses_recursive')) {
    /**
     * Get all traits used by a class, its parent classes, and trait hierarchies.
     *
     * @param object|string $class Class name or object
     * @return array<int|string, string>
     */
    function class_uses_recursive(object|string $class): array
    {
        return Reflection::classUsesRecursive($class);
    }
}

if (!function_exists('trait_uses_recursive')) {
    /**
     * Get all traits used by a trait and its parent traits.
     *
     * @param string $trait Trait name
     * @return array<int|string, string>
     */
    function trait_uses_recursive(string $trait): array
    {
        return Reflection::traitUsesRecursive($trait);
    }
}

if (!function_exists('class_basename')) {
    /**
     * Get the class basename of the given object or class name.
     *
     * @param object|string $class Object or class name
     * @return string
     */
    function class_basename(object|string $class): string
    {
        return Reflection::classBasename($class);
    }
}

if (!function_exists('filled')) {
    /**
     * Determine if the given value is filled.
     *
     * @return bool
     */
    function filled(mixed $value): bool
    {
        return Value::filled($value);
    }
}

if (!function_exists('blank')) {
    /**
     * Determine if the given value is blank.
     *
     * @return bool
     */
    function blank(mixed $value): bool
    {
        return Value::blank($value);
    }
}

if (!function_exists('generate_fake_data_for_json_schema_type')) {
    /**
     * Generate fake data from a JSON schema type.
     *
     * @param \Crustum\JsonSchema\Types\Type $type The schema type
     */
    function generate_fake_data_for_json_schema_type(Type $type): mixed
    {
        return SchemaDataGenerator::generate($type);
    }
}
