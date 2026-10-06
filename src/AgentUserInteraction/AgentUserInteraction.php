<?php
declare(strict_types=1);

namespace Crustum\Ai\AgentUserInteraction;

use Cake\Collection\CollectionInterface;
use Cake\Http\ServerRequest;
use Cake\Utility\Text;
use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Audio;
use Crustum\Ai\Files\Base64Audio;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\Base64Video;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\Image;
use Crustum\Ai\Files\RemoteAudio;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\RemoteVideo;
use Crustum\Ai\Files\Video;
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
 * The Agent User Interaction (AG-UI) protocol's input side.
 *
 * See: https://docs.ag-ui.com/concepts/messages
 */
class AgentUserInteraction
{
    /**
     * Create a chat instance from a RunAgentInput request or its array representation.
     *
     * @param \Cake\Http\ServerRequest|array<string, mixed> $input Run agent input
     */
    public static function chat(ServerRequest|array $input): Chat
    {
        return new Chat($input instanceof ServerRequest ? (array)$input->getData() : $input);
    }

    /**
     * Create messages from a list of AG-UI messages.
     *
     * @param iterable<int, mixed> $messages AG-UI messages
     * @return list<\Crustum\Ai\Messages\Message>
     */
    public static function fromMessages(iterable $messages): array
    {
        $result = [];
        $calls = [];
        $toolResults = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? null;

            if ($role === 'tool') {
                $toolResult = static::toolResultFrom($message, $calls);

                if ($toolResult instanceof ToolResult) {
                    $toolResults[] = $toolResult;
                }

                continue;
            }

            $result = static::flushToolResults($result, $toolResults);
            $toolResults = [];

            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $result[] = $converted = static::fromMessage($message);

            if ($converted instanceof AssistantMessage) {
                foreach ($converted->toolCalls as $call) {
                    $calls[$call->id] = $call;
                }
            }
        }

        return static::flushToolResults($result, $toolResults);
    }

    /**
     * Create a message from a single AG-UI message.
     *
     * @param array<string, mixed> $message AG-UI message
     */
    public static function fromMessage(array $message): Message
    {
        $content = $message['content'] ?? null;

        return match ($message['role'] ?? null) {
            'user' => new UserMessage(static::textFrom($content), static::attachmentsFrom($content)),
            'assistant' => new AssistantMessage(static::textFrom($content), collection(static::toolCallsFrom($message))),
            'tool' => new ToolResultMessage(collection(array_filter([static::toolResultFrom($message)]))),
            default => throw new InvalidArgumentException('Invalid message role.'),
        };
    }

    /**
     * Create approval decisions from a RunAgentInput's resume entries.
     *
     * @param iterable<int, mixed> $resume Resume entries
     */
    public static function decisionsFrom(iterable $resume): ?Decisions
    {
        $decisions = [];

        foreach ($resume as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = $entry['interruptId'] ?? null;

            if (!is_string($id) || Value::blank($id)) {
                continue;
            }

            $status = $entry['status'] ?? null;

            if ($status === 'cancelled') {
                $decisions[$id] = Decision::reject();

                continue;
            }

            if ($status === 'resolved') {
                $approved = $entry['payload']['approved'] ?? null;

                if (is_bool($approved)) {
                    $decisions[$id] = $approved;
                }
            }
        }

        return $decisions === [] ? null : Decisions::from($decisions);
    }

    /**
     * Convert messages or conversation message entities into state for hydrating an AG-UI client.
     *
     * @param iterable<int, \Crustum\Ai\Messages\Message|\Crustum\Ai\Model\Entity\ConversationMessage> $messages Messages
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>}
     */
    public static function toClientState(iterable $messages): array
    {
        $messages = is_array($messages) ? $messages : iterator_to_array($messages);

        return [
            'messages' => static::uiMessagesFrom($messages),
            'interrupts' => static::toInterrupts($messages),
        ];
    }

    /**
     * @param list<\Crustum\Ai\Messages\Message|\Crustum\Ai\Model\Entity\ConversationMessage> $messages Messages
     * @return list<array<string, mixed>>
     */
    protected static function uiMessagesFrom(array $messages): array
    {
        $result = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $toolResult) {
                    $result[] = static::toolMessageFrom($toolResult->toArray());
                }

                continue;
            }

            $role = $message instanceof Message ? $message->role->value : $message->role;

            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $id = $message instanceof ConversationMessage ? (string)$message->id : Text::uuid();

            if ($role === 'user') {
                $result[] = ['id' => $id, 'role' => 'user', 'content' => static::hydratedContent($message)];

                continue;
            }

            $toolCalls = static::hydratedToolCalls($message);
            $ownResults = $message instanceof ConversationMessage ? (array)($message->tool_results ?? []) : [];

            if (Value::filled($message->content) || $toolCalls !== []) {
                $result[] = [
                    'id' => $id,
                    'role' => 'assistant',
                    ...(Value::filled($message->content) ? ['content' => $message->content] : []),
                    ...($toolCalls !== [] ? ['toolCalls' => $toolCalls] : []),
                ];
            }

            foreach ($ownResults as $toolResult) {
                $result[] = static::toolMessageFrom($toolResult, $id);
            }
        }

        return $result;
    }

    /**
     * Get the open interrupts held by stored conversation messages.
     *
     * @param iterable<int, \Crustum\Ai\Messages\Message|\Crustum\Ai\Model\Entity\ConversationMessage> $messages Messages
     * @return list<array<string, mixed>>
     */
    public static function toInterrupts(iterable $messages): array
    {
        $interrupts = [];

        foreach ($messages as $message) {
            if (!$message instanceof ConversationMessage) {
                continue;
            }

            foreach (static::toolCallArrays($message) as $call) {
                if (!isset($call['id']) || !PendingApproval::isPending($call)) {
                    continue;
                }

                $interrupts[] = static::interrupt(
                    (string)$call['id'],
                    is_string($call['approval_reason'] ?? null) ? $call['approval_reason'] : null,
                    is_string($call['name'] ?? null) ? $call['name'] : null,
                    is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                );
            }
        }

        return $interrupts;
    }

    /**
     * Get the interrupt that represents a pending tool approval.
     *
     * @param array<string, mixed> $arguments Tool arguments
     * @return array<string, mixed>
     */
    public static function interrupt(string $id, ?string $reason = null, ?string $tool = null, array $arguments = []): array
    {
        return [
            'id' => $id,
            'reason' => 'approval_required',
            ...(Value::filled($reason) ? ['message' => $reason] : []),
            'toolCallId' => $id,
            'metadata' => [
                'kind' => 'approval',
                'toolName' => (string)$tool,
                'input' => (object)$arguments,
            ],
            'responseSchema' => [
                'type' => 'object',
                'properties' => ['approved' => ['type' => 'boolean']],
                'required' => ['approved'],
            ],
        ];
    }

    /**
     * Get the AG-UI tool message that represents a stored tool result.
     *
     * @param array<string, mixed> $toolResult Tool result
     * @return array<string, mixed>
     */
    protected static function toolMessageFrom(array $toolResult, ?string $messageId = null): array
    {
        $denied = ($toolResult['denied'] ?? false) === true;
        $failed = ($toolResult['failed'] ?? false) === true;

        $content = match (true) {
            $denied => 'The tool call was denied.',
            is_string($toolResult['result'] ?? null) => $toolResult['result'],
            default => static::json($toolResult['result'] ?? null),
        };

        return [
            'id' => $toolResult['result_id'] ?? $messageId . '-' . $toolResult['id'],
            'role' => 'tool',
            'toolCallId' => $toolResult['id'],
            'content' => $content,
            ...($denied || $failed ? ['error' => $content] : []),
            ...($denied ? ['metadata' => ['denied' => true]] : []),
        ];
    }

    /**
     * Get the AG-UI content that represents a stored message's text and attachments.
     *
     * @param \Crustum\Ai\Messages\Message|\Crustum\Ai\Model\Entity\ConversationMessage $message Message
     * @return list<array<string, mixed>>|string
     */
    protected static function hydratedContent(Message|ConversationMessage $message): string|array
    {
        $parts = static::attachmentPartsFrom($message);

        if ($parts === []) {
            return (string)$message->content;
        }

        return [
            ...(Value::filled($message->content) ? [['type' => 'text', 'text' => $message->content]] : []),
            ...$parts,
        ];
    }

    /**
     * Get the AG-UI content parts that represent a stored message's attachments.
     *
     * @param \Crustum\Ai\Messages\Message|\Crustum\Ai\Model\Entity\ConversationMessage $message Message
     * @return list<array<string, mixed>>
     */
    protected static function attachmentPartsFrom(Message|ConversationMessage $message): array
    {
        if ($message instanceof UserMessage) {
            $attachments = iterator_to_array($message->attachments);
        } elseif ($message instanceof ConversationMessage) {
            $attachments = [];

            foreach (static::decodedList($message->attachments ?? []) as $attachment) {
                $attachments[] = File::fromArray($attachment);
            }
        } else {
            $attachments = [];
        }

        $parts = [];

        foreach ($attachments as $file) {
            if (!$file instanceof File) {
                continue;
            }

            $part = static::contentPartFrom($file);

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * Get the AG-UI content part that represents the given file.
     *
     * @param \Crustum\Ai\Files\File $file File
     * @return array<string, mixed>|null
     */
    protected static function contentPartFrom(File $file): ?array
    {
        try {
            $mime = method_exists($file, 'declaredMimeType')
                ? $file->declaredMimeType()
                : $file->mimeType();
        } catch (Throwable) {
            return null;
        }

        try {
            $source = match (true) {
                isset($file->url) => ['type' => 'url', 'value' => $file->url],
                isset($file->base64) => ['type' => 'data', 'value' => $file->base64],
                $file instanceof StorableFile => ['type' => 'data', 'value' => base64_encode($file->content())],
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        if ($source === null || ($source['type'] === 'data' && Value::blank($mime))) {
            return null;
        }

        try {
            $name = $file->name();
        } catch (Throwable) {
            $name = null;
        }

        return [
            'type' => match (true) {
                $file instanceof Image => 'image',
                $file instanceof Audio => 'audio',
                $file instanceof Video => 'video',
                default => 'document',
            },
            'source' => [...$source, ...(Value::filled($mime) ? ['mimeType' => $mime] : [])],
            ...(Value::filled($name) ? ['metadata' => ['filename' => $name]] : []),
        ];
    }

    /**
     * Get the AG-UI tool calls that represent a stored message's tool calls.
     *
     * @param \Crustum\Ai\Messages\Message|\Crustum\Ai\Model\Entity\ConversationMessage $message Message
     * @return list<array<string, mixed>>
     */
    protected static function hydratedToolCalls(Message|ConversationMessage $message): array
    {
        if ($message instanceof AssistantMessage) {
            $calls = [];

            foreach ($message->toolCalls as $call) {
                $calls[] = $call->toArray();
            }
        } elseif ($message instanceof ConversationMessage) {
            $calls = static::toolCallArrays($message);
        } else {
            $calls = [];
        }

        return array_map(fn(array $call): array => [
            'id' => $call['id'],
            'type' => 'function',
            'function' => [
                'name' => $call['name'],
                'arguments' => static::json((object)($call['arguments'] ?? [])),
            ],
            ...(is_string($call['reasoning_encrypted_content'] ?? null)
                ? ['encryptedValue' => $call['reasoning_encrypted_content']]
                : []),
        ], $calls);
    }

    /**
     * Get the stored tool call arrays of a conversation message entity.
     *
     * @return list<array<string, mixed>>
     */
    protected static function toolCallArrays(ConversationMessage $message): array
    {
        return static::decodedList($message->tool_calls ?? []);
    }

    /**
     * Get the stored tool result arrays of a conversation message entity.
     *
     * @return list<array<string, mixed>>
     */
    protected static function toolResultArrays(ConversationMessage $message): array
    {
        return static::decodedList($message->tool_results ?? []);
    }

    /**
     * Decode a stored JSON column into a list of arrays, tolerating strings and malformed values.
     *
     * @return list<array<string, mixed>>
     */
    protected static function decodedList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }

    /**
     * Append a tool result message for the given buffered tool results.
     *
     * @param list<\Crustum\Ai\Messages\Message> $messages Messages
     * @param list<\Crustum\Ai\Responses\Data\ToolResult> $toolResults Tool results
     * @return list<\Crustum\Ai\Messages\Message>
     */
    protected static function flushToolResults(array $messages, array $toolResults): array
    {
        if ($toolResults === []) {
            return $messages;
        }

        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolResult> $results */
        $results = collection($toolResults);

        return [...$messages, new ToolResultMessage($results)];
    }

    /**
     * Create a tool result from an AG-UI tool message, naming it from the tool call it settles.
     *
     * @param array<string, mixed> $message AG-UI message
     * @param array<string, \Crustum\Ai\Responses\Data\ToolCall> $calls Known tool calls
     */
    protected static function toolResultFrom(array $message, array $calls = []): ?ToolResult
    {
        $id = $message['toolCallId'] ?? null;

        if (!is_string($id) || Value::blank($id)) {
            return null;
        }

        $call = $calls[$id] ?? null;

        $content = $message['content'] ?? null;
        $error = $message['error'] ?? null;

        return new ToolResult(
            id: $id,
            name: $call instanceof ToolCall ? $call->name : '',
            arguments: $call instanceof ToolCall ? $call->arguments : [],
            result: Value::filled($error) ? $error : $content,
            resultId: is_string($message['id'] ?? null) ? $message['id'] : null,
        );
    }

    /**
     * Get the tool calls declared on an AG-UI assistant message.
     *
     * @param array<string, mixed> $message AG-UI message
     * @return array<int, \Crustum\Ai\Responses\Data\ToolCall>
     */
    protected static function toolCallsFrom(array $message): array
    {
        $calls = [];

        foreach ((array)($message['toolCalls'] ?? []) as $call) {
            if (!is_array($call) || !is_string($call['id'] ?? null) || !is_string($call['function']['name'] ?? null)) {
                continue;
            }

            $calls[] = new ToolCall(
                id: $call['id'],
                name: $call['function']['name'],
                arguments: static::arguments($call['function']['arguments'] ?? null),
                reasoningEncryptedContent: is_string($call['encryptedValue'] ?? null) ? $call['encryptedValue'] : null,
            );
        }

        return $calls;
    }

    /**
     * Decode an AG-UI tool call's JSON encoded arguments.
     *
     * @return array<string, mixed>
     */
    protected static function arguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        return is_string($arguments) ? (array)json_decode($arguments, true) : [];
    }

    /**
     * Get the text held by AG-UI message content.
     */
    protected static function textFrom(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        /** @var \Cake\Collection\CollectionInterface<int, string> $texts */
        $texts = collection(is_array($content) ? $content : [])
            ->filter(fn(mixed $part): bool => is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null))
            ->map(fn(array $part): string => $part['text']);

        return implode(PHP_EOL . PHP_EOL, $texts->toList());
    }

    /**
     * Get the attachments held by AG-UI message content.
     *
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Files\File>
     */
    protected static function attachmentsFrom(mixed $content): CollectionInterface
    {
        /** @var array<int, \Crustum\Ai\Files\File> $files */
        $files = [];

        foreach (is_array($content) ? $content : [] as $part) {
            if (!is_array($part) || !in_array($part['type'] ?? null, ['image', 'audio', 'video', 'document'], true)) {
                continue;
            }

            $file = static::fileFrom($part);

            if ($file instanceof File) {
                $files[] = $file;
            }
        }

        return collection($files);
    }

    /**
     * Create a file instance from an AG-UI multimodal content part.
     *
     * @param array<string, mixed> $part Content part
     */
    protected static function fileFrom(array $part): ?File
    {
        $source = $part['source'] ?? [];
        $value = is_array($source) ? $source['value'] ?? null : null;
        $mime = is_array($source) ? $source['mimeType'] ?? null : null;

        if (!is_string($value) || Value::blank($value) || !is_string($mime) && $mime !== null) {
            return null;
        }

        $mime = $mime === null ? null : strtolower($mime);
        $sourceType = $source['type'] ?? null;

        if (!in_array($sourceType, ['url', 'data'], true) || ($sourceType === 'data' && Value::blank($mime))) {
            return null;
        }

        $remote = $sourceType === 'url';

        $file = match ($part['type']) {
            'image' => $remote ? new RemoteImage($value, $mime) : new Base64Image($value, $mime),
            'audio' => $remote ? new RemoteAudio($value, $mime) : new Base64Audio($value, $mime),
            'video' => $remote ? new RemoteVideo($value, $mime) : new Base64Video($value, $mime),
            default => $remote ? new RemoteDocument($value, $mime) : new Base64Document($value, $mime),
        };

        $filename = $part['metadata']['filename'] ?? null;

        return $file->as(is_string($filename) ? $filename : null);
    }

    /**
     * Encode the given value as JSON, substituting bytes a provider streamed as invalid UTF-8.
     */
    protected static function json(mixed $value): string
    {
        return (string)json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
