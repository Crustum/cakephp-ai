<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Fake;

use Cake\Utility\Text;
use Crustum\JsonSchema\Types\AnyOfType;
use Crustum\JsonSchema\Types\ArrayType;
use Crustum\JsonSchema\Types\BooleanType;
use Crustum\JsonSchema\Types\IntegerType;
use Crustum\JsonSchema\Types\NumberType;
use Crustum\JsonSchema\Types\ObjectType;
use Crustum\JsonSchema\Types\StringType;
use Crustum\JsonSchema\Types\Type;
use RuntimeException;

/**
 * Generates fake data from JSON schema types for testing.
 */
class SchemaDataGenerator
{
    /**
     * Generate fake data from a JSON schema type.
     *
     * @param \Crustum\JsonSchema\Types\Type $type The schema type
     */
    public static function generate(Type $type): mixed
    {
        $attributes = (fn(): array => get_object_vars($type))->call($type);

        if (isset($attributes['enum']) && is_array($attributes['enum']) && $attributes['enum'] !== []) {
            $enumValue = $attributes['enum'][array_rand($attributes['enum'])];

            return $type::class === ArrayType::class
                ? [$enumValue]
                : $enumValue;
        }

        return $attributes['default'] ?? match ($type::class) {
            ObjectType::class => (function () use ($attributes): array {
                $result = [];

                foreach ($attributes['properties'] ?? [] as $key => $property) {
                    $result[$key] = static::generate($property);
                }

                return $result;
            })(),

            ArrayType::class => (function () use ($attributes): array {
                $min = $attributes['minItems'] ?? 1;
                $max = $attributes['maxItems'] ?? max($min, 3);

                $count = random_int($min, $max);

                if (!isset($attributes['items'])) {
                    return [];
                }

                $result = [];

                for ($index = 0; $index < $count; $index++) {
                    $result[] = static::generate($attributes['items']);
                }

                return $result;
            })(),

            AnyOfType::class => (fn(): mixed => static::generate($attributes['schemas'][0] ?? throw new RuntimeException('AnyOf schema must contain at least one branch.')))(),

            StringType::class => (function () use ($attributes): string {
                if (isset($attributes['format'])) {
                    return match ($attributes['format']) {
                        'date' => date('Y-m-d'),
                        'date-time' => date('c'),
                        'email' => 'user@example.com',
                        'time' => date('H:i:s'),
                        'uri', 'url' => 'https://example.com',
                        'uuid' => Text::uuid(),
                        default => 'string',
                    };
                }

                $min = $attributes['minLength'] ?? 1;
                $max = $attributes['maxLength'] ?? max($min, 10);

                return substr(Text::uuid(), 0, random_int($min, $max));
            })(),

            IntegerType::class => (function () use ($attributes): int {
                $min = $attributes['minimum'] ?? 0;
                $max = $attributes['maximum'] ?? max($min, 100);

                return random_int($min, $max);
            })(),

            NumberType::class => (function () use ($attributes): float|int {
                $min = $attributes['minimum'] ?? 0.0;
                $max = $attributes['maximum'] ?? max($min, 100.0);

                return $min + mt_rand() / mt_getrandmax() * ($max - $min);
            })(),

            BooleanType::class => random_int(0, 1) === 0,

            default => null,
        };
    }
}
