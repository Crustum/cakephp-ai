<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

/**
 * Decodes Structured Output Trait
 *
 * Decodes structured JSON output from model responses, tolerating markdown fences.
 */
trait DecodesStructuredOutputTrait
{
    /**
     * Decode a structured output payload, tolerating markdown code fences.
     *
     * @param string|null $text Raw model output
     * @return array<string, mixed>
     */
    protected function decodeStructuredOutput(?string $text): array
    {
        $trimmed = trim((string)$text);

        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        if (!is_array($decoded)) {
            $decoded = json_decode($this->extractJsonCodeFence($trimmed) ?? '', true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extract the contents of a markdown code fence from the payload, if present.
     *
     * @param string $text Raw model output
     * @return string|null
     */
    private function extractJsonCodeFence(string $text): ?string
    {
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/si', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
