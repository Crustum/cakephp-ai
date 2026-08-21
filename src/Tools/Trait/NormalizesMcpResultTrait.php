<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools\Trait;

/**
 * Normalizes MCP tool results into tool output.
 */
trait NormalizesMcpResultTrait
{
    /**
     * Format an MCP error payload into tool output.
     *
     * @param string $text Error text
     * @return string
     */
    protected function errorMessage(string $text): string
    {
        return $text === ''
            ? 'MCP tool error.'
            : 'MCP tool error: ' . $text;
    }

    /**
     * Encode structured MCP content as JSON.
     *
     * @param array<string, mixed> $content Structured content
     * @return string
     */
    protected function json(array $content): string
    {
        return json_encode($content, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
