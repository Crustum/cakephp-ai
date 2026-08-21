<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support;

use Crustum\Ai\Audio;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Reranking;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\RerankingResponse;

/**
 * Fluent string helper exposing audio and rerank helpers used in tests.
 */
class StringableHelper
{
    /**
     * @param string $value String value
     */
    public function __construct(protected string $value)
    {
    }

    /**
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param string|null $voice Voice name
     * @param string|null $instructions Voice instructions
     * @param string|null $model Model name
     * @param int|null $timeout Request timeout
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    public function toAudio(
        Lab|array|string|null $provider = null,
        ?string $voice = null,
        ?string $instructions = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AudioResponse {
        $pending = Audio::of($this->value);

        if ($voice !== null) {
            $pending = $pending->voice($voice);
        }

        if ($instructions !== null) {
            $pending = $pending->instructions($instructions);
        }

        if ($timeout !== null) {
            $pending = $pending->timeout($timeout);
        }

        return $pending->generate(provider: $provider, model: $model);
    }

    /**
     * @param string $query Reranking query
     * @param int|null $limit Result limit
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param string|null $model Model name
     * @return \Crustum\Ai\Responses\RerankingResponse
     */
    public function rerank(
        string $query,
        ?int $limit = null,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): RerankingResponse {
        return Reranking::of([$this->value])
            ->limit($limit)
            ->rerank($query, $provider, $model);
    }
}
