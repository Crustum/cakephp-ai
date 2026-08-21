<?php
declare(strict_types=1);

namespace Crustum\Ai\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\Ai\AnonymousAgent;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Responses\StructuredAgentResponse;
use Override;
use Throwable;

/**
 * Interactive chat with an ad-hoc agent.
 *
 * Usage:
 * ```
 * bin/cake ai chat
 * bin/cake ai chat --prompt "Hello"
 * ```
 */
class ChatCommand extends Command
{
    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'ai chat';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Chat with an ad-hoc Ai agent';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addOption('prompt', [
                'help' => 'Send a single prompt and exit (non-interactive).',
                'default' => null,
            ])
            ->addOption('instructions', [
                'help' => 'System instructions for the agent.',
                'default' => 'You are a helpful assistant.',
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $instructions = (string)$args->getOption('instructions');
        $agent = new AnonymousAgent($instructions, [], []);

        $single = $args->getOption('prompt');

        if (is_string($single) && $single !== '') {
            return $this->runPrompt($agent, $single, $io);
        }

        $io->out('Type a prompt, or "exit" / "quit" to stop.');

        while (true) {
            $prompt = $io->ask('Prompt');

            if ($prompt === '' || in_array(strtolower($prompt), ['exit', 'quit'], true)) {
                return static::CODE_SUCCESS;
            }

            $result = $this->runPrompt($agent, $prompt, $io);

            if ($result !== static::CODE_SUCCESS) {
                return $result;
            }
        }
    }

    /**
     * Run one agent prompt and print the response.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param string $prompt User prompt
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int
     */
    protected function runPrompt(Agent $agent, string $prompt, ConsoleIo $io): int
    {
        $io->out('Thinking...');

        try {
            $response = $agent->prompt($prompt);
        } catch (Throwable $throwable) {
            $io->err($throwable->getMessage());

            return static::CODE_ERROR;
        }

        if ($response instanceof StructuredAgentResponse) {
            $io->out((string)json_encode($response->structured, JSON_PRETTY_PRINT));
        } else {
            $io->out((string)$response);
        }

        $io->out('');

        return static::CODE_SUCCESS;
    }
}
