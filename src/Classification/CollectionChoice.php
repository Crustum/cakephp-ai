<?php
declare(strict_types=1);

namespace Crustum\Ai\Classification;

use ArrayAccess;
use BackedEnum;
use Cake\Collection\CollectionInterface;
use Cake\Utility\Hash;
use Closure;
use Crustum\Ai\Classification;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Responses\Data\ChoiceAnswer;
use InvalidArgumentException;
use Stringable;
use UnexpectedValueException;
use UnitEnum;

/**
 * Choice between the items of a collection.
 *
 * A string-keyed collection of strings without a "by" or "describe" resolver maps option names to descriptions, and its keys are chosen.
 */
final class CollectionChoice
{
    /**
     * The option names mapped to their descriptions.
     *
     * @var array<string, string|array<string, mixed>|null>
     */
    protected array $options = [];

    /**
     * The option names mapped to the items they represent.
     *
     * @var array<string, mixed>
     */
    protected array $items = [];

    /**
     * Create a new choice between the items of the given collection.
     *
     * A string-keyed collection of strings without a "by" or "describe" resolver maps option names to descriptions, and its keys are chosen.
     *
     * @param \Cake\Collection\CollectionInterface<array-key, mixed> $collection Items to choose between
     * @param \Closure(mixed): mixed|string|null $by Field or closure that names each item
     * @param \Closure(mixed): mixed|array<int, string>|string|null $describe Field, fields, or closure that describe each item
     * @throws \InvalidArgumentException if an item cannot be named or two items share a name
     */
    public function __construct(
        CollectionInterface $collection,
        Closure|string|null $by = null,
        Closure|array|string|null $describe = null,
    ) {
        $all = $collection->toArray();

        $inline = $by === null && $describe === null && $all !== []
            && array_all($all, fn(mixed $value, mixed $key): bool => is_string($key) && is_string($value));

        foreach ($inline ? array_keys($all) : array_values($all) as $item) {
            $name = $this->resolveLabel($item, $by);

            if (array_key_exists($name, $this->items)) {
                throw new InvalidArgumentException("Multiple items resolve to the option name [{$name}].");
            }

            $this->options[$name] = $inline ? $all[$item] : $this->resolveDescription($item, $describe);
            $this->items[$name] = $item;
        }
    }

    /**
     * Choose the item that best answers the question about the given text.
     *
     * @param array<string, mixed>|string $text Text to classify
     * @param float|null $threshold Minimum probability of the chosen option; below it, null is returned
     * @param \Crustum\Ai\Enums\Lab|array<int, \Crustum\Ai\Enums\Lab|string>|string|null $provider Provider
     * @param string|null $model Model name
     * @param int|null $timeout Request timeout in seconds
     * @return mixed
     * @throws \InvalidArgumentException if fewer than two options are available
     */
    public function decide(
        string $question,
        string|array $text,
        ?float $threshold = null,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): mixed {
        $request = Classification::of($text)->question('decision', new Choice($question, $this->options));

        if ($timeout !== null) {
            $request->timeout($timeout);
        }

        $answer = $request->classify($provider, $model)->answer('decision');

        if (!$answer instanceof ChoiceAnswer) {
            throw new UnexpectedValueException('Decision classification must return a choice answer.');
        }

        if ($threshold !== null && $answer->probabilityOf($answer->choice) < $threshold) {
            return null;
        }

        return $this->items[$answer->choice] ?? null;
    }

    /**
     * Resolve the option name for the given item.
     *
     * @param mixed $item Collection item
     * @param \Closure(mixed): mixed|string|null $by Field or closure that names each item
     * @return string
     * @throws \InvalidArgumentException if the item cannot be named
     */
    protected function resolveLabel(mixed $item, Closure|string|null $by): string
    {
        $label = match (true) {
            $by instanceof Closure => $by($item),
            is_string($by) => $this->valueAt($item, $by),
            $item instanceof BackedEnum && is_string($item->value) => $item->value,
            $item instanceof UnitEnum => $item->name,
            default => $item,
        };

        if (!is_string($label) && !$label instanceof Stringable) {
            throw new InvalidArgumentException('Unable to determine an option name for the item; pass a "by" field or closure.');
        }

        return (string)$label;
    }

    /**
     * Resolve the option description for the given item.
     *
     * @param mixed $item Collection item
     * @param \Closure(mixed): mixed|array<int, string>|string|null $describe Field, fields, or closure that describe each item
     * @return array<string, mixed>|string|null
     */
    protected function resolveDescription(mixed $item, Closure|array|string|null $describe): string|array|null
    {
        return match (true) {
            $describe instanceof Closure => $describe($item),
            is_array($describe) => $this->describeFields($item, $describe),
            is_string($describe) => $this->valueAt($item, $describe),
            default => null,
        };
    }

    /**
     * Describe an item with the given fields.
     *
     * @param mixed $item Collection item
     * @param array<int, string> $describe Describing fields
     * @return array<string, mixed>
     */
    protected function describeFields(mixed $item, array $describe): array
    {
        $described = [];

        foreach ($describe as $field) {
            $described[$field] = $this->valueAt($item, $field);
        }

        return $described;
    }

    /**
     * Read a (possibly nested) field from an array or object item.
     *
     * @param mixed $item Collection item
     * @param string $field Dot-notated field path
     * @return mixed
     */
    protected function valueAt(mixed $item, string $field): mixed
    {
        if (is_array($item) || $item instanceof ArrayAccess) {
            return Hash::get($item, $field);
        }

        if (is_object($item)) {
            $current = $item;

            foreach (explode('.', $field) as $segment) {
                if (is_object($current) && isset($current->{$segment})) {
                    $current = $current->{$segment};

                    continue;
                }

                if ((is_array($current) || $current instanceof ArrayAccess) && isset($current[$segment])) {
                    $current = $current[$segment];

                    continue;
                }

                return null;
            }

            return $current;
        }

        return null;
    }
}
