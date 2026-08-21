<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Crustum\Ai\Responses\Data\Citation as CitationData;
use Crustum\Ai\Responses\Data\UrlCitation;
use UnhandledMatchError;

/**
 * Citation event.
 *
 * Represents a citation or source reference in a streaming response.
 */
class Citation extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $messageId Message ID
     * @param \Crustum\Ai\Responses\Data\Citation $citation Citation data
     * @param int $timestamp Unix timestamp
     */
    public function __construct(
        public string $id,
        public string $messageId,
        public CitationData $citation,
        public int $timestamp,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'citation',
            'message_id' => $this->messageId,
            'citation' => match (true) {
                $this->citation instanceof UrlCitation => [
                    'title' => $this->citation->title,
                    'url' => $this->citation->url,
                ],
                default => throw new UnhandledMatchError(
                    'Citation serialization supports UrlCitation only; got ' . $this->citation::class,
                ),
            },
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @inheritDoc
     */
    public function toVercelProtocolArray(): ?array
    {
        return match (true) {
            $this->citation instanceof UrlCitation => array_filter([
                'type' => 'source-url',
                'sourceId' => $this->citation->url,
                'url' => $this->citation->url,
                'title' => $this->citation->title,
            ], fn($value): bool => $value !== null),
            default => null,
        };
    }
}
