<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

/**
 * Merges configured HTTP headers into request headers case-insensitively.
 */
trait MergesHeadersTrait
{
    /**
     * Merge configured headers over request headers, deduping by lowercase
     * name while preserving the first-seen header casing.
     *
     * @param array<string, mixed> $headers Request headers
     * @param array<string, mixed> $configuredHeaders Configured headers
     * @return array<string, mixed>
     */
    protected function mergeConfiguredHeaders(array $headers, array $configuredHeaders): array
    {
        $merged = array_merge($headers, $configuredHeaders);

        $grouped = [];

        foreach ($merged as $name => $value) {
            $key = strtolower((string)$name);

            if (!array_key_exists($key, $grouped)) {
                $grouped[$key] = ['name' => $name, 'value' => $value];
            } else {
                $grouped[$key]['value'] = $value;
            }
        }

        $result = [];

        foreach ($grouped as $entry) {
            $result[$entry['name']] = $entry['value'];
        }

        return $result;
    }
}
