<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Utility\Value;

/**
 * Composes Schema Instructions Trait
 *
 * Appends structured output schema instructions to system prompts.
 */
trait ComposesSchemaInstructionsTrait
{
    /**
     * Compose system instructions with optional JSON schema constraints.
     *
     * @param string|null $instructions Base system instructions
     * @param array<string, mixed>|null $schema Response schema definition
     */
    protected function composeInstructions(?string $instructions, ?array $schema): ?string
    {
        if (Value::blank($schema)) {
            return $instructions;
        }

        $schemaJson = json_encode((new ObjectSchema($schema))->toSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $schemaInstruction = sprintf(
            'You MUST respond EXCLUSIVELY with a JSON object that strictly adheres to the following schema. Do NOT explain or add other content. Validate your response against this schema:' . "\n%s",
            $schemaJson,
        );

        return Value::blank($instructions)
            ? $schemaInstruction
            : $instructions . "\n\n" . $schemaInstruction;
    }
}
