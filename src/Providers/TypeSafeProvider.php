<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\ClassificationGateway;
use Crustum\Ai\Contracts\Providers\ClassificationProvider;
use Crustum\Ai\Gateway\TypeSafeGateway;
use Crustum\Ai\Providers\Trait\ClassifiesTrait;
use Crustum\Ai\Providers\Trait\HasClassificationGatewayTrait;
use Override;

/**
 * TypeSafe classification provider.
 */
class TypeSafeProvider extends Provider implements ClassificationProvider
{
    use ClassifiesTrait;
    use HasClassificationGatewayTrait;

    /**
     * Provider configuration.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Event manager instance.
     */
    protected EventManagerInterface $events;

    /**
     * Shared TypeSafe gateway instance.
     */
    protected ?TypeSafeGateway $typeSafeGateway = null;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Provider configuration
     * @param \Cake\Event\EventManagerInterface|null $events Event manager instance
     */
    public function __construct(
        array $config,
        ?EventManagerInterface $events = null,
    ) {
        $this->config = $config;
        $this->events = $events ?? EventManager::instance();
        $this->config['name'] ??= 'typesafe';
        $this->config['driver'] ??= 'typesafe';
        $this->config['key'] ??= $this->config['apiKey'] ?? null;
    }

    /**
     * Get the credentials for the underlying AI provider.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function providerCredentials(): array
    {
        return ['key' => $this->config['key'] ?? null];
    }

    /**
     * Get the shared TypeSafe gateway instance.
     *
     * @return \Crustum\Ai\Gateway\TypeSafeGateway
     */
    protected function typeSafeGateway(): TypeSafeGateway
    {
        return $this->typeSafeGateway ??= new TypeSafeGateway();
    }

    /**
     * Get the provider's classification gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ClassificationGateway
     */
    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway ??= $this->typeSafeGateway();
    }

    /**
     * Get the name of the default classification model.
     *
     * @return string
     */
    public function defaultClassificationModel(): string
    {
        return $this->config['models']['classification']['default'] ?? 'jev-latest';
    }
}
