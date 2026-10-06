<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

/**
 * Score Answer
 *
 * Represents a level-scored classification answer with per-level probabilities.
 */
class ScoreAnswer extends Answer
{
    /**
     * Per-level probabilities keyed by integer level.
     *
     * @var array<int, float>
     */
    public readonly array $probabilities;

    /**
     * Legend entries keyed by integer level.
     *
     * @var array<int, string|array<string, mixed>>
     */
    public readonly array $legend;

    /**
     * Constructor
     *
     * @param float $score The probability-weighted level, which may fall between two levels.
     * @param array<int|string, float> $probabilities Per-level probabilities.
     * @param array<int|string, string|array<string, mixed>> $legend Legend entries per level.
     * @param float|null $confidence Distribution certainty, or null when unreported.
     */
    public function __construct(
        public readonly float $score,
        array $probabilities,
        array $legend,
        public readonly ?float $confidence = null,
    ) {
        /** @var array<int, float> $probabilities */
        $probabilities = self::withIntegerKeys($probabilities);
        /** @var array<int, string|array<string, mixed>> $legend */
        $legend = self::withIntegerKeys($legend);
        $this->probabilities = $probabilities;
        $this->legend = $legend;
    }

    /**
     * Get the most probable level.
     *
     * @return int The level with the highest probability, or the rounded score when no distribution was reported.
     */
    public function level(): int
    {
        return $this->probabilities === []
            ? (int)round($this->score)
            : (int)array_search(max($this->probabilities), $this->probabilities, true);
    }

    /**
     * Get the description of the most probable level.
     *
     * @return array<string, mixed>|string|null The legend entry, or null when absent.
     */
    public function label(): string|array|null
    {
        return $this->legend[$this->level()] ?? null;
    }

    /**
     * Get the score as a fraction of the highest level.
     *
     * @return float The normalized score.
     */
    public function normalized(): float
    {
        return count($this->legend) > 1 ? $this->score / (count($this->legend) - 1) : 0.0;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed> The array representation.
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'probabilities' => $this->probabilities,
            'legend' => $this->legend,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * Cast level keys to integers and sort by level.
     *
     * @param array<array-key, mixed> $levels Levels keyed by numeric string or integer.
     * @return array<int, mixed> Levels keyed by integer, sorted ascending.
     */
    protected static function withIntegerKeys(array $levels): array
    {
        /** @var \Cake\Collection\CollectionInterface<int, mixed> $combined */
        $combined = collection($levels)->combine(
            fn(mixed $value, string|int $key): int => (int)$key,
            fn(mixed $value): mixed => $value,
        );

        $result = $combined->toArray();
        ksort($result);

        return $result;
    }
}
