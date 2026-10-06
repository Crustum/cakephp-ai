<?php
declare(strict_types=1);

use Cake\Collection\Collection;
use Cake\Core\Configure;
use Cake\Http\Client\Exception\ClientException;
use Cake\I18n\DateTime;
use Cake\Queue\TestSuite\TestQueueClient;
use Crustum\Ai\AnonymousAgent;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Gateway\Fake\SchemaDataGenerator;
use Crustum\Ai\Pipeline\Pipeline;
use Crustum\Ai\Providers\OpenAiCompatibleProvider;
use Crustum\Ai\StructuredAnonymousAgent;
use Crustum\Ai\Test\Support\Database\ConversationTable;
use Crustum\JsonSchema\Types\Type;

if (!class_exists('RequestException', false)) {
    class_alias(ClientException::class, 'RequestException');
}

if (!function_exists('aiDbExists')) {
    /**
     * Determine if a conversation row exists for the given table conditions.
     *
     * @param string $table Table name.
     * @param array<string, mixed> $conditions Conditions.
     * @param string|null $connection Connection name.
     * @return bool
     */
    function aiDbExists(string $table, array $conditions, ?string $connection = null): bool
    {
        return ConversationTable::exists($table, $conditions, $connection);
    }
}

if (!function_exists('agent')) {
    /**
     * Get an ad-hoc agent instance.
     *
     * @param string $instructions System instructions
     * @param iterable<int, mixed> $messages Initial messages
     * @param iterable<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     * @param \Closure|null $schema Structured output schema builder
     * @return \Crustum\Ai\Contracts\Agent
     */
    function agent(
        string $instructions = '',
        iterable $messages = [],
        iterable $tools = [],
        ?Closure $schema = null,
    ): Agent {
        return $schema instanceof Closure
            ? new StructuredAnonymousAgent($instructions, $messages, $tools, $schema)
            : new AnonymousAgent($instructions, $messages, $tools);
    }
}

if (!function_exists('pipeline')) {
    /**
     * Get a new pipeline instance.
     *
     * @return \Crustum\Ai\Pipeline\Pipeline
     */
    function pipeline(): Pipeline
    {
        return new Pipeline();
    }
}

if (!function_exists('tap')) {
    /**
     * Call the given callback with the given value then return the value.
     *
     * @template TValue
     * @param TValue $value Value to tap
     * @param \Closure|null $callback Optional callback
     * @return TValue
     */
    function tap(mixed $value, ?Closure $callback = null): mixed
    {
        if ($callback instanceof Closure) {
            $callback($value);
        }

        return $value;
    }
}

if (!function_exists('generate_fake_data_for_json_schema_type')) {
    /**
     * Generate fake data from a JSON schema type (test helper).
     *
     * @param \Crustum\JsonSchema\Types\Type $type The schema type
     */
    function generate_fake_data_for_json_schema_type(Type $type): mixed
    {
        return SchemaDataGenerator::generate($type);
    }
}

if (!function_exists('collect')) {
    /**
     * Create a collection from the given value.
     *
     * @return \Cake\Collection\Collection<int|string, mixed>
     */
    function collect(mixed $value = []): Collection
    {
        return collection($value);
    }
}

if (!function_exists('now')) {
    /**
     * Get the current datetime.
     *
     * @return \Cake\I18n\DateTime
     */
    function now(): DateTime
    {
        return DateTime::now();
    }
}

if (!function_exists('days')) {
    /**
     * Create a day interval.
     *
     * @param int $days Number of days.
     * @return \DateInterval
     */
    function days(int $days): DateInterval
    {
        return new DateInterval('P' . $days . 'D');
    }
}

if (!function_exists('aiSyncQueue')) {
    /**
     * Enable or disable CrustumQueue sync (in-process) dispatch for tests.
     *
     * @param bool $enabled Whether sync dispatch should run jobs in-process
     */
    function aiSyncQueue(bool $enabled = true): void
    {
        Configure::write('CrustumQueue.sync', $enabled);
    }
}

if (!function_exists('aiFakeQueue')) {
    /**
     * Fake the queue so jobs are captured instead of executed (test helper).
     */
    function aiFakeQueue(): void
    {
        aiSyncQueue(false);
        TestQueueClient::clearQueuedJobs();
        TestQueueClient::replaceAllClients();
    }
}

if (!function_exists('rrmdir')) {
    /**
     * Recursively remove a directory tree (test helper).
     *
     * @param string $dir Directory to remove
     */
    function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}

if (!function_exists('retry')) {
    /**
     * Retry the given callback until it succeeds or attempts are exhausted.
     *
     * @template TValue
     * @param int $times Maximum attempts.
     * @param callable(): TValue $callback Callback to execute.
     * @param int $sleepMs Milliseconds to sleep between attempts.
     * @return TValue
     */
    function retry(int $times, callable $callback, int $sleepMs = 0): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $times) {
            try {
                return $callback();
            } catch (Throwable $exception) {
                $lastException = $exception;
                $attempts++;

                if ($attempts < $times && $sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        throw $lastException;
    }
}

if (!function_exists('configureOpenAiCompatible')) {
    /**
     * Configure the OpenAI-compatible test provider (test helper).
     */
    function configureOpenAiCompatible(): void
    {
        Configure::write('Ai.providers.openai-compatible', [
            'className' => OpenAiCompatibleProvider::class,
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
            'models' => ['text' => ['default' => 'local-model']],
        ]);
    }
}
