<?php
declare(strict_types=1);

namespace Crustum\Ai\Vercel;

use Cake\Collection\Collection;
use Cake\Http\ServerRequest;
use Cake\Utility\Text;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Base64Audio;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\Base64Video;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\RemoteAudio;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\RemoteVideo;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Model\Entity\ConversationMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Utility\Value;
use InvalidArgumentException;
use Throwable;

/**
 * Vercel AI SDK Protocol Helper
 *
 * Converts between Vercel AI SDK UI messages and Cake AI message types.
 */
class Vercel
{
    /**
     * Create a chat instance from a useChat request or its UI message list.
     *
     * @param \Cake\Http\ServerRequest|iterable<int, array<string, mixed>>|array{messages?: iterable<int, array<string, mixed> >} $input UI messages or request data
     * @return \Crustum\Ai\Vercel\Chat
     */
    public static function chat(iterable|ServerRequest $input): Chat
    {
        $messages = [];

        if ($input instanceof ServerRequest) {
            $messages = $input->getData('messages', []);
        } elseif (is_array($input) && array_key_exists('messages', $input)) {
            $messages = (array)$input['messages'];
        } elseif (is_array($input)) {
            $messages = $input;
        } elseif (is_iterable($input)) {
            $messages = [...$input];
        }

        return new Chat(array_values((array)$messages));
    }

    /**
     * Convert messages or conversation message models into UI message arrays.
     *
     * @param iterable<int, \Crustum\Ai\Messages\Message|\Crustum\Ai\Model\Entity\ConversationMessage> $messages Messages to convert
     * @return list<array<string, mixed>>
     */
    public static function toUiMessages(iterable $messages): array
    {
        $result = [];
        $partRefs = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $toolResult) {
                    static::applyUiToolOutput($result, $partRefs, $toolResult->id, $toolResult->result, $toolResult->denied);
                }

                continue;
            }

            $role = $message instanceof Message ? $message->role->value : ($message->role ?? '');

            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $parts = static::uiPartsFrom($message);

            foreach ($parts as $index => $part) {
                if (isset($part['toolCallId'])) {
                    $partRefs[$part['toolCallId']] = [count($result), $index];
                }
            }

            $id = $message instanceof ConversationMessage ? ($message->id ?? Text::uuid()) : Text::uuid();

            $result[] = [
                'id' => $id,
                'role' => $role,
                'parts' => $parts,
            ];

            if ($message instanceof ConversationMessage) {
                foreach ($message->tool_results ?? [] as $toolResult) {
                    static::applyUiToolOutput($result, $partRefs, $toolResult['id'] ?? '', $toolResult['result'] ?? null, $toolResult['denied'] ?? false);
                }
            }
        }

        return $result;
    }

    /**
     * Create UI message parts for a single message.
     *
     * @return list<array<string, mixed>>
     */
    protected static function uiPartsFrom(Message|ConversationMessage $message): array
    {
        $parts = [];
        $meta = $message instanceof ConversationMessage ? (array)($message->get('meta') ?? []) : [];
        $provider = (string)($meta['provider'] ?? '');

        foreach ($message instanceof ConversationMessage ? (array)($message->get('steps') ?? []) : [] as $step) {
            if (Value::filled($step['reasoning'] ?? null)) {
                $parts[] = ['type' => 'reasoning', 'text' => $step['reasoning']];
            }

            foreach ((array)($step['provider_tool_calls'] ?? []) as $call) {
                $parts[] = [
                    'type' => 'custom',
                    'kind' => $provider . '.' . ($call['type'] ?? ''),
                    'providerMetadata' => [$provider => ['itemId' => $call['id'] ?? '', 'status' => 'completed', 'data' => $call['data'] ?? []]],
                ];
            }
        }

        $content = $message->content ?? '';

        if (!empty($content)) {
            $parts[] = ['type' => 'text', 'text' => $content];
        }

        foreach (static::attachmentsFrom($message) as $attachment) {
            $part = static::uiFilePartFrom($attachment);

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        foreach (static::toolCallArraysFrom($message) as $toolCall) {
            $toolId = $toolCall['id'] ?? '';
            $isPending = PendingApproval::isPending($toolCall);

            $parts[] = [
                'type' => 'tool-' . ($toolCall['name'] ?? ''),
                'toolCallId' => $toolId,
                'state' => $isPending ? 'approval-requested' : 'input-available',
                'input' => $toolCall['arguments'] ?? [],
                ...($isPending
                    ? ['approval' => ['id' => $toolId, 'reason' => $toolCall['approval_reason']]]
                    : []),
            ];
        }

        return $parts;
    }

    /**
     * Settle a hydrated tool part with its result.
     *
     * @param array<int, array<string, mixed>> $result
     * @param array<string, array{int, int}> $partRefs
     */
    protected static function applyUiToolOutput(array &$result, array $partRefs, string $id, mixed $output, bool $denied): void
    {
        if (!isset($partRefs[$id])) {
            return;
        }

        [$message, $part] = $partRefs[$id];

        $result[$message]['parts'][$part]['state'] = $denied ? 'output-denied' : 'output-available';
        unset($result[$message]['parts'][$part]['approval']);

        if (!$denied) {
            $result[$message]['parts'][$part]['output'] = $output;
        }
    }

    /**
     * Get a message's tool calls as their array representations.
     *
     * @return iterable<int, array<string, mixed>>
     */
    protected static function toolCallArraysFrom(Message|ConversationMessage $message): iterable
    {
        return match (true) {
            $message instanceof AssistantMessage => $message->toolCalls->map(fn(ToolCall $tc): array => $tc->toArray())->toList(),
            $message instanceof ConversationMessage => $message->tool_calls ?? [],
            default => [],
        };
    }

    /**
     * Create messages from UI message arrays.
     *
     * @param iterable<int, array<string, mixed>> $messages
     * @return list<\Crustum\Ai\Messages\Message>
     */
    public static function fromUiMessages(iterable $messages): array
    {
        $result = [];

        foreach ($messages as $message) {
            if (!in_array($message['role'] ?? null, ['user', 'assistant'], true)) {
                continue;
            }

            $result[] = $converted = static::fromUiMessage($message);

            if ($converted instanceof AssistantMessage) {
                $toolResults = static::toolResultsFrom($message);

                if ($toolResults->count() > 0) {
                    $result[] = new ToolResultMessage($toolResults);
                }
            }
        }

        return $result;
    }

    /**
     * Create a message from a single UI message array.
     *
     * @param array<string, mixed> $message
     */
    public static function fromUiMessage(array $message): Message
    {
        $parts = $message['parts'] ?? [];
        $textParts = array_filter($parts, fn(array $p): bool => ($p['type'] ?? '') === 'text');
        $text = implode(PHP_EOL . PHP_EOL, array_column($textParts, 'text'));

        return match ($message['role'] ?? null) {
            'user' => new UserMessage($text, (new Collection(array_filter($parts, fn(array $p): bool => ($p['type'] ?? '') === 'file')))
                ->map(fn(array $part): ?File => static::fileFrom($part))
                ->filter()
                ->values()),
            'assistant' => new AssistantMessage(
                $text,
                collection(array_map(fn(array $part): ToolCall => new ToolCall(
                    id: $part['toolCallId'],
                    name: substr($part['type'], strlen('tool-')),
                    arguments: is_array($part['input'] ?? null) ? $part['input'] : [],
                ), array_filter($parts, fn(array $p): bool => str_starts_with($p['type'] ?? '', 'tool-')))),
            ),
            default => throw new InvalidArgumentException('Invalid message role.'),
        };
    }

    /**
     * Get tool approval responses from a UI message.
     *
     * @param array<string, mixed> $message
     * @return array<string, bool>
     */
    public static function approvalResponsesFrom(array $message): array
    {
        $result = [];

        foreach ($message['parts'] ?? [] as $part) {
            if (!is_array($part) || !str_starts_with($part['type'] ?? '', 'tool-')) {
                continue;
            }

            if (!isset($part['toolCallId'])) {
                continue;
            }

            $state = $part['state'] ?? '';
            if (!in_array($state, ['approval-requested', 'approval-responded'], true)) {
                continue;
            }

            $approved = $part['approval']['approved'] ?? null;
            if (is_bool($approved)) {
                $result[$part['toolCallId']] = $approved;
            }
        }

        return $result;
    }

    /**
     * Create tool results from a UI message's settled tool parts.
     *
     * @param array<string, mixed> $message
     * @return \Cake\Collection\Collection<int, \Crustum\Ai\Responses\Data\ToolResult>
     */
    protected static function toolResultsFrom(array $message): Collection
    {
        $parts = array_filter($message['parts'] ?? [], fn($p): bool => is_array($p)
            && str_starts_with($p['type'] ?? '', 'tool-')
            && isset($p['toolCallId']));

        return collect(array_filter($parts, fn(array $part): bool => in_array($part['state'] ?? null, ['output-available', 'output-error', 'output-denied'], true)))
            ->map(fn(array $part): ToolResult => new ToolResult(
                id: $part['toolCallId'],
                name: substr($part['type'], strlen('tool-')),
                arguments: is_array($part['input'] ?? null) ? $part['input'] : [],
                result: $part['output'] ?? $part['errorText'] ?? null,
                denied: ($part['state'] ?? null) === 'output-denied',
            ));
    }

    /**
     * Get a message's attachments as file instances.
     *
     * @param \Crustum\Ai\Messages\Message|\Crustum\Ai\Model\Entity\ConversationMessage $message
     * @return \Cake\Collection\Collection<int, \Crustum\Ai\Files\File>
     */
    protected static function attachmentsFrom(Message|ConversationMessage $message): Collection
    {
        return match (true) {
            $message instanceof UserMessage => $message->attachments ?? new Collection([]),
            $message instanceof ConversationMessage => (new Collection($message->attachments ?? []))
                ->map(fn($attachment): ?File => is_array($attachment) ? File::fromArray($attachment) : null)
                ->filter()
                ->values(),
            default => new Collection([]),
        };
    }

    /**
     * Create a UI message file part from a file instance.
     *
     * @param \Crustum\Ai\Files\File $file
     * @return array<string, mixed>|null
     */
    protected static function uiFilePartFrom(File $file): ?array
    {
        try {
            $mime = method_exists($file, 'declaredMimeType')
                ? $file->declaredMimeType()
                : $file->mimeType();
        } catch (Throwable) {
            $mime = 'application/octet-stream';
        }

        $mime ??= 'application/octet-stream';

        try {
            $url = match (true) {
                isset($file->url) => $file->url,
                isset($file->base64) => 'data:' . $mime . ';base64,' . $file->base64,
                $file instanceof StorableFile => 'data:' . $mime . ';base64,' . base64_encode($file->content()),
                default => null,
            };
        } catch (Throwable) {
            $url = null;
        }

        if ($url === null || $url === '') {
            return null;
        }

        $part = [
            'type' => 'file',
            'mediaType' => $mime,
            'url' => $url,
        ];

        $name = $file->name();

        if ($name !== null && $name !== '') {
            $part['filename'] = $name;
        }

        return $part;
    }

    /**
     * Filter the given parts down to the tool parts.
     *
     * @param iterable<int, mixed> $parts
     * @return \Cake\Collection\Collection<int, array<string, mixed>>
     */
    protected static function toolParts(iterable $parts): Collection
    {
        return (new Collection($parts))->filter(fn($part): bool => is_array($part)
            && str_starts_with($part['type'] ?? '', 'tool-')
            && isset($part['toolCallId']));
    }

    /**
     * Get the tool name encoded in a UI tool part's type.
     *
     * @param array<string, mixed> $part
     */
    protected static function toolName(array $part): string
    {
        return substr($part['type'], strlen('tool-'));
    }

    /**
     * Create a file instance from a Vercel AI SDK UI message file part.
     *
     * @param array<string, mixed> $part
     * @return \Crustum\Ai\Files\File|null
     */
    protected static function fileFrom(array $part): ?File
    {
        $url = $part['url'] ?? '';
        $mime = $part['mediaType'] ?? '';

        if (!is_string($url) || !is_string($mime)) {
            return null;
        }

        $mime = strtolower($mime);

        $file = match (true) {
            $url === '' => null,
            str_starts_with($url, 'data:') => static::fileFromDataUrl($url, $mime),
            str_starts_with($mime, 'image/') => new RemoteImage($url, $mime),
            str_starts_with($mime, 'audio/') => new RemoteAudio($url, $mime),
            str_starts_with($mime, 'video/') => new RemoteVideo($url, $mime),
            default => new RemoteDocument($url, $mime ?: null),
        };

        $filename = $part['filename'] ?? null;

        return $file?->as(is_string($filename) ? $filename : null);
    }

    /**
     * Create a base64 file instance from a data URL.
     *
     * @param string $url
     * @param string $mime
     * @return \Crustum\Ai\Files\File|null
     */
    protected static function fileFromDataUrl(string $url, string $mime): ?File
    {
        $mime = $mime ?: strtolower(str_replace('data:', '', strstr($url, ';', true) ?: ''));

        $base64 = str_contains($url, 'base64,') ? substr($url, strpos($url, 'base64,') + 7) : '';

        return match (true) {
            $base64 === '' => null,
            str_starts_with($mime, 'image/') => new Base64Image($base64, $mime),
            str_starts_with($mime, 'audio/') => new Base64Audio($base64, $mime),
            str_starts_with($mime, 'video/') => new Base64Video($base64, $mime),
            default => new Base64Document($base64, $mime ?: null),
        };
    }
}
