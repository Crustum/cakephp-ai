<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Failover;

use Crustum\Ai\Event\AgentFailedOverEvent;
use Crustum\Ai\Event\ProviderFailedOverEvent;
use Crustum\Ai\TestSuite\Capture\EventCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts a provider failover occurred from a named provider.
 *
 * @internal
 */
class ProviderFailedOver extends Constraint
{
    /**
     * @param string $from Provider that failed
     * @param string|null $to Optional provider failed over to
     */
    public function __construct(
        protected string $from,
        protected ?string $to = null,
    ) {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        foreach (EventCapture::events() as $event) {
            if ($event instanceof AgentFailedOverEvent) {
                if ($event->provider->name() === $this->from && $this->to === null) {
                    return true;
                }

                continue;
            }

            if ($event instanceof ProviderFailedOverEvent) {
                $from = $event->getData('from');
                $to = $event->getData('to');

                if ($from === $this->from && ($this->to === null || $to === $this->to)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        if ($this->to === null) {
            return sprintf('provider failed over from [%s]', $this->from);
        }

        return sprintf('provider failed over from [%s] to [%s]', $this->from, $this->to);
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return $this->toString() . "\n" . EventCapture::timeline();
    }
}
