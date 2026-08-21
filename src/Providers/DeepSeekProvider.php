<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\DeepSeek\DeepSeekGateway;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;
use Override;

/**
 * DeepSeek text provider.
 */
class DeepSeekProvider extends Provider implements TextProvider
{
    use GeneratesTextTrait;
    use HasTextGatewayTrait;
    use StreamsTextTrait;

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
        $this->config['name'] ??= 'deepseek';
        $this->config['driver'] ??= 'deepseek';
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
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new DeepSeekGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     *
     * @return string
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'deepseek-v4-flash';
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'deepseek-v4-flash';
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'deepseek-reasoner';
    }
}
