<?php
declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

return Architecture::define()
    // Where to scan: `Source` with empty paths = read composer.json PSR-4 mappings
    // (src/ + tests/). The PHPUnit extension relies on this layer to know what to
    // analyse; the CLI (`analyze src`) passes the path explicitly instead.
    ->layer('Source', [])
    // Foundation is resolved first (first-match wins), so shared value types that
    // physically live in domain folders are pulled down to the leaf here.
    ->layerPattern('Foundation', [
        '/^Crustum\\\\Ai\\\\(Contracts|Enums|Exception|Messages|Responses|Schema|Streaming|Support|Utility|Attributes|Registry)(\\\\.*)?$/',
        '/^Crustum\\\\Ai\\\\Tools\\\\Request$/',
        '/^Crustum\\\\Ai\\\\Approvals\\\\(Approval|Decision|Decisions)$/',
        '/^Crustum\\\\Ai\\\\Prompts\\\\(AgentPrompt|Prompt)$/',
        '/^Crustum\\\\Ai\\\\Gateway\\\\(StepContext|StepResponse|TextGenerationOptions)$/',
        '/^Crustum\\\\Ai\\\\Providers\\\\Tools\\\\(ProviderTool|CodeExecution|FileSearch|FileSearchQuery|ToolSearch|WebFetch|WebSearch)$/',
    ])
    // Public static entrypoints (root FQCN kept for DX; layer is Facades, not Plugin).
    // `Files` stays with the Files layer — DTOs/traits call `Files::put()` / etc.
    // `Ai` is intentionally unregistered: every layer calls Ai::manager(); unregistered
    // classes are treated as external, so refs to it never fail the ruleset.
    ->layerPattern('Facades', '/^Crustum\\\\Ai\\\\(AiManager|Audio|Collections|Embeddings|Image|Reranking|Text|Transcription)$/')
    // Vector-store entry + entity (same idea as root Files with the Files layer).
    ->layerPattern('Stores', '/^Crustum\\\\Ai\\\\Stores?$/')
    // Root agent classes live beside facades on disk but belong with Agents.
    ->layerPattern('Agents', [
        '/^Crustum\\\\Ai\\\\Agents\\\\.*$/',
        '/^Crustum\\\\Ai\\\\(AnonymousAgent|StructuredAnonymousAgent)$/',
    ])
    ->layerPattern('PendingResponses', [
        '/^Crustum\\\\Ai\\\\(PendingResponses|Job)(\\\\.*)?$/',
        '/^Crustum\\\\Ai\\\\FakePendingDispatch$/',
    ])
    // Bootstrap only — not the modality facades.
    ->layerPattern('Plugin', [
        '/^Crustum\\\\Ai\\\\AiPlugin$/',
        '/^Crustum\\\\Ai\\\\Command\\\\.*$/',
    ])
    ->layerPattern('Event', '/^Crustum\\\\Ai\\\\Event\\\\.*$/')
    ->layerPattern('RunContext', '/^Crustum\\\\Ai\\\\Gateway\\\\RunContext$/')
    ->layerPattern('Gateway', '/^Crustum\\\\Ai\\\\Gateway\\\\.*$/')
    ->layerPattern('Providers', '/^Crustum\\\\Ai\\\\Providers\\\\.*$/')
    ->layerPattern('Files', '/^Crustum\\\\Ai\\\\(Files|Filesystem)(\\\\.*)?$/')
    ->layerPattern('Storage', '/^Crustum\\\\Ai\\\\Storage(\\\\.*)?$/')
    ->layerPattern('Middleware', '/^Crustum\\\\Ai\\\\(Middleware|Pipeline)\\\\.*$/')
    ->layerPattern('Model', '/^Crustum\\\\Ai\\\\Model\\\\.*$/')
    ->layerPattern('Tools', '/^Crustum\\\\Ai\\\\Tools\\\\.*$/')
    ->layerPattern('Prompts', '/^Crustum\\\\Ai\\\\Prompts\\\\.*$/')
    ->layerPattern('Approvals', '/^Crustum\\\\Ai\\\\Approvals\\\\.*$/')
    ->layerPattern('TestSuite', '/^Crustum\\\\Ai\\\\TestSuite\\\\.*$/')
    // `+Layer` = that layer + its allowed deps (Cake +Cache/+Utility).
    // Spine: Foundation ← Approvals ← Prompts.
    // Do not use +Event until Event is Foundation-only.
    ->ruleset([
        'Foundation' => ['RunContext'],
        'RunContext' => ['Foundation', 'Event'],
        'Approvals' => ['Foundation'],
        'Model' => ['Approvals', 'Foundation'],
        'Prompts' => ['+Approvals'],
        'Files' => ['Model', '+Prompts', 'PendingResponses'],
        'Tools' => ['Files', 'Foundation'],
        'Middleware' => ['+Prompts', 'Model', 'RunContext'],
        'Stores' => ['Files', 'Foundation'],
        'Storage' => ['Foundation', 'Model', 'Approvals', 'Files'],
        'Gateway' => ['+Tools', '+Prompts', 'Stores', 'Event', 'RunContext'],
        'Providers' => ['+Gateway', 'Middleware', 'Model', 'Event'],
        'Event' => ['Providers', '+Prompts', 'Stores'],
        'PendingResponses' => ['+Providers'],
        'Agents' => ['+Providers', '+PendingResponses'],
        'Facades' => ['+Agents', '+Storage'],
        'Plugin' => ['+Facades'],
        'TestSuite' => ['+Facades'],
    ])
    // Root Files entry returns FakeFileGateway from fake(); DTOs stay Files-only via ruleset.
    ->skipClassViolation('Crustum\\Ai\\Files', 'Crustum\\Ai\\Gateway\\FakeFileGateway')
    // Root Stores entry returns FakeStoreGateway from fake().
    ->skipClassViolation('Crustum\\Ai\\Stores', 'Crustum\\Ai\\Gateway\\FakeStoreGateway')
    // Contracts / Foundation tools typed against Store entity.
    ->skipClassViolation('Crustum\\Ai\\Contracts\\Providers\\StoreProvider', 'Crustum\\Ai\\Store')
    ->skipClassViolation('Crustum\\Ai\\Contracts\\Gateway\\StoreGateway', 'Crustum\\Ai\\Store')
    ->skipClassViolation('Crustum\\Ai\\Providers\\Tools\\FileSearch', 'Crustum\\Ai\\Store')
    // Support helper iterates the Reranking facade.
    ->skipClassViolation('Crustum\\Ai\\Support\\AiCollection', 'Crustum\\Ai\\Reranking')
    // Vector search behavior generates embeddings for similarity queries.
    ->skipClassViolation('Crustum\\Ai\\Model\\Behavior\\VectorSearchBehavior', 'Crustum\\Ai\\Embeddings')
    // Value wrapper delegates to the Text facade.
    ->skipClassViolation('Crustum\\Ai\\Support\\TextStringable', 'Crustum\\Ai\\Text')
    // Contracts reference the orchestration DTO they drive.
    ->skipClassViolation('Crustum\\Ai\\Contracts\\Providers\\TextProvider', 'Crustum\\Ai\\Gateway\\TextGenerationLoop')
    ->skipClassViolation('Crustum\\Ai\\Contracts\\Files\\TranscribableAudio', 'Crustum\\Ai\\PendingResponses\\PendingTranscriptionGeneration')
    ->skipClassViolation('Crustum\\Ai\\Streaming\\Protocols\\AgentUserInteractionProtocol', 'Crustum\\Ai\\Approvals\\ApprovalMismatchException')
    ->skipClassViolation('Crustum\\Ai\\Contracts\\PaginatesConversations', 'Crustum\\Ai\\Storage\\ConversationCursor')
    ->skipClassViolation('Crustum\\Ai\\Contracts\\PaginatesConversations', 'Crustum\\Ai\\Storage\\ConversationMessagePage')
    ;
