<?php
declare(strict_types=1);

namespace Crustum\Ai\PendingResponses;

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Ai;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\ProviderFailedOver;
use Crustum\Ai\Exception\FailoverableException;
use Crustum\Ai\PendingResponses\Trait\ResolvesProviderOptionsTrait;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\RerankingResponse;
use Crustum\Ai\Trait\ConditionableTrait;
use InvalidArgumentException;

/**
 * Pending reranking request.
 *
 * Builder for configuring and executing document reranking requests.
 * Reranks documents based on their relevance to a query using AI providers.
 */
class PendingReranking
{
    use ConditionableTrait;
    use ResolvesProviderOptionsTrait;

    /**
     * Maximum number of results to return.
     */
    protected ?int $limit = null;

    /**
     * Timeout in seconds for the reranking request.
     */
    protected int $timeout = 30;

    /**
     * Create a new pending reranking instance.
     *
     * @param array<int, mixed> $documents The documents to rerank
     * @throws \InvalidArgumentException if the documents are not a list, are empty, or contain non-string or blank entries.
     */
    public function __construct(
        protected array $documents,
    ) {
        if (!array_is_list($documents)) {
            throw new InvalidArgumentException('Documents to rerank must be a list, not an associative array.');
        }

        if ($documents === []) {
            throw new InvalidArgumentException('At least one document is required to rerank.');
        }

        foreach ($documents as $index => $document) {
            if (!is_string($document) || trim($document) === '') {
                throw new InvalidArgumentException(sprintf('Each document to rerank must be a non-blank string (index %d).', $index));
            }
        }
    }

    /**
     * Limit the number of results to return.
     *
     * @param int|null $limit Maximum number of results
     */
    public function limit(?int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the reranking request.
     *
     * @param int $seconds Timeout in seconds
     */
    public function timeout(int $seconds = 30): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Rerank the documents based on their relevance to the query.
     *
     * @param string $query The query to rank documents against
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\RerankingResponse
     * @throws \Crustum\Ai\Exception\FailoverableException if every configured provider fails to rerank the documents.
     */
    public function rerank(string $query, Lab|array|string|null $provider = null, ?string $model = null): RerankingResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? Configure::read('Ai.default_for_reranking'),
            $model,
        );

        $lastException = null;

        foreach ($providers as $provider => $model) {
            $provider = Ai::manager()->fakeableRerankingProvider($provider);

            $model ??= $provider->defaultRerankingModel();

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            $provider = $provider->withHeaders($headers);

            try {
                return $provider->rerank($this->documents, $query, $this->limit, $model, $this->timeout, $providerOptions);
            } catch (FailoverableException $e) {
                $lastException = $e;

                EventManager::instance()->dispatch(new ProviderFailedOver($provider->name(), $model, $e, $provider));

                continue;
            }
        }

        throw $lastException;
    }
}
