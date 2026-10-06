<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use ArrayAccess;
use Cake\Validation\Validator;
use Crustum\Ai\Exception\ValidationException;

/**
 * Tool Request
 *
 * Represents the request parameters passed to a tool invocation.
 * Provides array access to the tool arguments.
 *
 * @implements \ArrayAccess<string, mixed>
 */
class Request implements ArrayAccess
{
    /**
     * Constructor
     *
     * @param array<string, mixed> $arguments The tool invocation arguments.
     * @param string|null $toolCallId Stable provider tool-call ID.
     * @param string|null $toolInvocationId Tool invocation correlation ID.
     */
    public function __construct(
        protected array $arguments = [],
        protected ?string $toolCallId = null,
        protected ?string $toolInvocationId = null,
    ) {
    }

    /**
     * Get the stable provider tool-call ID, usable as an external idempotency key.
     *
     * @return string|null
     */
    public function toolCallId(): ?string
    {
        return $this->toolCallId;
    }

    /**
     * Get the ID correlating this execution with the tool invocation events dispatched around it.
     *
     * @return string|null
     */
    public function toolInvocationId(): ?string
    {
        return $this->toolInvocationId;
    }

    /**
     * Get a string argument value.
     *
     * @param string $key Argument key.
     * @param string|null $default Default value.
     * @return string
     */
    public function string(string $key, ?string $default = null): string
    {
        return (string)($this->arguments[$key] ?? $default ?? '');
    }

    /**
     * Get a boolean argument value.
     *
     * @param string $key Argument key.
     * @param bool $default Default value.
     * @return bool
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->arguments[$key] ?? $default;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get an integer argument value.
     *
     * @param string $key Argument key.
     * @param int|null $default Default value.
     * @return int
     */
    public function integer(string $key, ?int $default = null): int
    {
        return (int)($this->arguments[$key] ?? $default ?? 0);
    }

    /**
     * Get all arguments or a subset by keys.
     *
     * @param array-key|array<array-key, string>|null $keys Optional keys to filter.
     * @return array<string, mixed> The arguments array.
     */
    public function all(mixed $keys = null): array
    {
        if (is_null($keys)) {
            return $this->data();
        }

        return array_intersect_key(
            $this->data(),
            array_flip(is_array($keys) ? $keys : func_get_args()),
        );
    }

    /**
     * Get the data for the request.
     *
     * @param mixed $key Optional key to retrieve specific value.
     * @param mixed $default Default value if key not found.
     * @return mixed The data or specific value.
     */
    protected function data(mixed $key = null, mixed $default = null): mixed
    {
        return is_null($key)
            ? $this->arguments
            : ($this->arguments[$key] ?? $default);
    }

    /**
     * Validate the request arguments against the given rules.
     *
     * Rules follow the set of rules: each field maps to a pipe-delimited string
     * or list of rule names (e.g. `'required|string'`).
     * Alternatively, a CakePHP validator instance may be passed directly for
     * full access to the framework's validation API.
     * On failure a {@see \Crustum\Ai\Exception\ValidationException} is thrown
     * carrying the nested validation errors.
     *
     * @param \Cake\Validation\Validator|array<string, string|array<int, string>> $rules Validator instance or field => rules map
     * @param array<string, mixed> $messages Custom messages (array rules only)
     * @param array<string, mixed> $attributes Custom attributes (array rules only)
     * @return array<string, mixed> The validated arguments (subset of the rules)
     * @throws \Crustum\Ai\Exception\ValidationException
     */
    public function validate(Validator|array $rules, array $messages = [], array $attributes = []): array
    {
        $validator = $rules instanceof Validator ? $rules : $this->buildValidator($rules, $messages);

        $data = $this->all();
        $errors = $validator->validate($data);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if (!is_array($rules)) {
            return array_filter(
                $data,
                fn($value, $field): bool => $validator->hasField((string)$field),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        return array_intersect_key($data, $rules);
    }

    /**
     * Build a CakePHP validator from pipe-shaped rules.
     *
     * @param array<string, string|array<int, string>> $rules Field => rules map
     * @param array<string, mixed> $messages Custom messages
     * @return \Cake\Validation\Validator
     */
    protected function buildValidator(array $rules, array $messages): Validator
    {
        $validator = new Validator();

        foreach ($rules as $field => $fieldRules) {
            $parsed = is_array($fieldRules) ? $fieldRules : explode('|', (string)$fieldRules);

            foreach ($parsed as $rule) {
                $this->applyValidationRule($validator, (string)$field, (string)$rule, $messages);
            }
        }

        return $validator;
    }

    /**
     * Apply a single validation rule to the validator.
     *
     * @param \Cake\Validation\Validator $validator The validator to extend
     * @param string $field The field being validated
     * @param string $rule The rule name (e.g. 'required', 'string', 'integer')
     * @param array<string, mixed> $messages Custom messages
     */
    protected function applyValidationRule(
        Validator $validator,
        string $field,
        string $rule,
        array $messages,
    ): void {
        $message = $messages[$field][$rule] ?? $messages[$field] ?? $messages[$field . '.' . $rule] ?? null;

        switch ($rule) {
            case 'required':
                $validator->requirePresence($field, true, $message ?? null);

                return;
            case 'string':
                $validator->add($field, 'validString', [
                    'rule' => is_string(...),
                    'message' => $message,
                ]);

                return;
            case 'integer':
                $validator->integer($field, $message ?? null);

                return;
            case 'numeric':
                $validator->numeric($field, $message ?? null);

                return;
            case 'boolean':
                $validator->boolean($field, $message ?? null);

                return;
            case 'email':
                $validator->email($field, $message ?? null);

                return;
            case 'scalar':
                $validator->scalar($field, $message ?? null);

                return;
            default:
                $validator->add($field, $rule, [
                    'rule' => [$rule],
                    'message' => $message,
                ]);
        }
    }

    /**
     * Get the arguments as an array.
     *
     * @return array<string, mixed> The arguments array.
     */
    public function toArray(): array
    {
        return $this->arguments;
    }

    /**
     * Determine if an item exists at an offset.
     *
     * @param mixed $offset The offset to check.
     * @return bool True if offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->arguments[$offset]);
    }

    /**
     * Get an item at a given offset.
     *
     * @param mixed $offset The offset to get.
     * @return mixed The value at offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->arguments[$offset];
    }

    /**
     * Set the item at a given offset.
     *
     * @param mixed $offset The offset to set.
     * @param mixed $value The value to set.
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->arguments[] = $value;
        } else {
            $this->arguments[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     *
     * @param mixed $offset The offset to unset.
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->arguments[$offset]);
    }
}
