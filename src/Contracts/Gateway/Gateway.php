<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

/**
 * Gateway Interface
 *
 * Main gateway interface that combines all specialized gateway capabilities.
 * This interface extends all individual gateway interfaces to provide a unified
 * entry point for AI operations including audio, embeddings, images, text, and transcription.
 */
interface Gateway extends
    AudioGateway,
    EmbeddingGateway,
    ImageGateway,
    StepTextGateway,
    TranscriptionGateway
{
}
