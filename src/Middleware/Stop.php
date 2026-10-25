<?php
declare(strict_types=1);

namespace Crustum\Ai\Middleware;

use Closure;
use Crustum\Ai\Gateway\StepResult;
use Crustum\Ai\PendingStep;

/**
 * Ends an agent run early, before the model is called for a step.
 */
class Stop
{
    /**
     * @param \Closure(\Crustum\Ai\PendingStep): bool|bool $condition Condition that ends the run
     */
    public function __construct(protected Closure|bool $condition)
    {
    }

    /**
     * Stop the run before any step for which the given condition holds.
     *
     * @param \Closure(\Crustum\Ai\PendingStep): bool|bool $condition Condition that ends the run
     * @return self
     */
    public static function when(Closure|bool $condition): self
    {
        return new self($condition);
    }

    /**
     * Stop the run before any step for which the given condition fails.
     *
     * @param \Closure(\Crustum\Ai\PendingStep): bool|bool $condition Condition that continues the run
     * @return self
     */
    public static function unless(Closure|bool $condition): self
    {
        return new self(fn(PendingStep $step): bool => !self::evaluate($condition, $step));
    }

    /**
     * Handle the pending step.
     *
     * @param \Crustum\Ai\PendingStep $step Pending step
     * @param \Closure $next Next middleware callback
     * @return mixed
     */
    public function handle(PendingStep $step, Closure $next): mixed
    {
        return self::evaluate($this->condition, $step) ? StepResult::stop() : $next($step);
    }

    /**
     * Evaluate the condition against the pending step.
     *
     * @param \Closure(\Crustum\Ai\PendingStep): bool|bool $condition Condition to evaluate
     * @param \Crustum\Ai\PendingStep $step Pending step
     * @return bool
     */
    protected static function evaluate(Closure|bool $condition, PendingStep $step): bool
    {
        return $condition instanceof Closure ? (bool)$condition($step) : $condition;
    }
}
