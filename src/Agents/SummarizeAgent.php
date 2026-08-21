<?php
declare(strict_types=1);

namespace Crustum\Ai\Agents;

use Cake\Utility\Inflector;
use Crustum\Ai\Attributes\UseCheapestModel;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Trait\PromptableTrait;

/**
 * Agent that summarizes text into a fixed number of sentences.
 */
#[UseCheapestModel]
final class SummarizeAgent implements Agent
{
    use PromptableTrait;

    protected int $sentences;

    /**
     * @param int $sentences Maximum number of sentences in the summary
     */
    public function __construct(int $sentences = 3)
    {
        $this->sentences = max(1, $sentences);
    }

    /**
     * Get the instructions that the agent should follow.
     *
     * @return string
     */
    public function instructions(): string
    {
        $unit = $this->sentences === 1
            ? 'sentence'
            : Inflector::pluralize('sentence');

        return sprintf(
            'Summarize the given text in no more than %d %s. Respond with only the summary and nothing else.',
            $this->sentences,
            $unit,
        );
    }
}
