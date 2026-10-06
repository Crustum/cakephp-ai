<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

use Exception;
use Throwable;

/**
 * Tool request validation exception.
 *
 * Thrown by {@see \Crustum\Ai\Tools\Request::validate()} when the tool
 * arguments fail validation. It carries the nested validation errors so the
 * invoking gateway can return them to the model as the tool result, instead
 * of reporting the invocation as a failed tool call.
 *
 * It intentionally does NOT extend AiException, so validation failures are
 * never routed through provider failover / retry logic.
 */
class ValidationException extends Exception
{
    /**
     * @param array $errors Validation errors keyed by field (nested field => list of messages)
     */
    public function __construct(
        protected array $errors,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?: 'The given data was invalid.', $code, $previous);
    }

    /**
     * Get the raw nested validation errors.
     *
     * @return array
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get all validation error messages as a flat list.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        $flat = [];

        array_walk_recursive($this->errors, function ($message): void {
            if (is_string($message)) {
                $flat[] = $message;
            }
        });

        return $flat;
    }
}
