<?php
declare(strict_types=1);

namespace Crustum\Ai\Storage;

use Cake\I18n\DateTime;
use Crustum\Ai\Enums\MessageStatus;
use Crustum\Ai\Utility\Value;
use DateTimeInterface;
use JsonSerializable;

/**
 * A conversation message as it was persisted, with its JSON columns decoded.
 *
 * `Message` is what the model sees; this is what was stored, and it keeps the
 * identity and timestamps a rendered transcript needs.
 */
class StoredMessage implements JsonSerializable
{
    /**
     * @param array<string, mixed> $usage Usage data
     * @param array<string, mixed> $meta Message meta
     * @param list<array<string, mixed>> $steps Steps
     * @param \Crustum\Ai\Enums\MessageStatus $status Message status
     * @param list<array<string, mixed>> $attachments Attachments
     */
    public function __construct(
        public string $id,
        public string $role,
        public string $content,
        public ?DateTimeInterface $createdAt = null,
        public array $usage = [],
        public array $meta = [],
        public array $steps = [],
        public MessageStatus $status = MessageStatus::Completed,
        public array $attachments = [],
    ) {
    }

    /**
     * Reconstruct an instance from a stored row, decoding its JSON columns.
     *
     * @param array<string, mixed> $record Stored row
     */
    public static function fromArray(array $record): self
    {
        $createdAt = $record['created'] ?? null;

        if ($createdAt instanceof DateTimeInterface) {
            $parsed = $createdAt;
        } elseif (Value::blank($createdAt)) {
            $parsed = null;
        } else {
            $parsed = new DateTime((string)$createdAt);
        }

        $status = $record['status'] ?? null;

        if ($status instanceof MessageStatus) {
            $parsedStatus = $status;
        } elseif (is_string($status) && $status !== '') {
            $parsedStatus = MessageStatus::from($status);
        } else {
            $parsedStatus = MessageStatus::Completed;
        }

        return new self(
            id: (string)($record['id'] ?? ''),
            role: (string)($record['role'] ?? ''),
            content: (string)($record['content'] ?? ''),
            createdAt: $parsed,
            usage: static::decoded($record['usage'] ?? null),
            meta: static::decoded($record['meta'] ?? null),
            steps: array_values(static::decoded($record['steps'] ?? null)),
            status: $parsedStatus,
            attachments: array_values(static::decoded($record['attachments'] ?? null)),
        );
    }

    /**
     * Get the instance as an array, in the shape it was stored in.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'created_at' => $this->createdAt?->format(DateTimeInterface::ATOM),
            'usage' => $this->usage,
            'meta' => $this->meta,
            'steps' => $this->steps,
            'status' => $this->status->value,
            'attachments' => $this->attachments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The tool calls made across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    public function toolCalls(): array
    {
        $collapsed = [];

        foreach ($this->steps as $step) {
            foreach ((array)($step['tool_calls'] ?? []) as $toolCall) {
                $collapsed[] = $toolCall;
            }
        }

        return $collapsed;
    }

    /**
     * The provider-hosted tool calls made across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    public function providerToolCalls(): array
    {
        $collapsed = [];

        foreach ($this->steps as $step) {
            foreach ((array)($step['provider_tool_calls'] ?? []) as $toolCall) {
                $collapsed[] = $toolCall;
            }
        }

        return $collapsed;
    }

    /**
     * The tool results recorded across every step of the turn, in step order.
     *
     * @return list<array<string, mixed>>
     */
    public function toolResults(): array
    {
        return array_values(array_map(
            fn(array $toolCall): array => array_intersect_key(
                $toolCall,
                ['id' => true, 'name' => true, 'arguments' => true, 'result' => true, 'result_id' => true, 'denied' => true, 'failed' => true],
            ),
            array_filter(
                $this->toolCalls(),
                fn(array $toolCall): bool => array_key_exists('result', $toolCall),
            ),
        ));
    }

    /**
     * Decode a JSON column, tolerating null and malformed values.
     *
     * @return array<mixed>
     */
    protected static function decoded(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string)($value ?: '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
