<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * Has Skills
 *
 * Contract for agents that expose skills to load at runtime.
 */
interface HasSkills
{
    /**
     * Get the skills available to the agent.
     *
     * @return iterable<\Closure|\Crustum\Ai\Skills\Skill|string>
     */
    public function skills(): iterable;
}
