<?php
declare(strict_types=1);

namespace Crustum\Ai\Pipeline;

use Closure;

/**
 * Middleware pipeline for agent prompts.
 */
class Pipeline
{
    protected mixed $passable;

    /**
     * @var array<int, mixed>
     */
    protected array $pipes = [];

    /**
     * Set the object being sent through the pipeline.
     *
     * @param mixed $passable Object to send
     */
    public function send(mixed $passable): static
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * Set the array of pipes.
     *
     * @param array<int, mixed> $pipes Middleware pipes
     */
    public function through(array $pipes): static
    {
        $this->pipes = $pipes;

        return $this;
    }

    /**
     * Run the pipeline with a final destination callback.
     *
     * @param \Closure $destination Final callback
     */
    public function then(Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            fn(Closure $next, mixed $pipe): Closure => function (mixed $passable) use ($next, $pipe): mixed {
                if (is_callable($pipe)) {
                    return $pipe($passable, $next);
                }

                return $pipe->handle($passable, $next);
            },
            $destination,
        );

        return $pipeline($this->passable);
    }
}
