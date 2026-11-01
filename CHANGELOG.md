# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0]

### Added

- Raw HTTP responses on classification, embeddings, transcription, image, and
  reranking responses: all five response types expose the provider response
  through `HasRawResponseTrait`, attached by every gateway (VoyageAi splits
  `$response`/`$data`; `PendingEmbeddingsGeneration` passes `raw` through,
  fully-cached generations expose `null`).
- OpenAI billing and quota errors now throw `InsufficientCreditsException`:
  402 or 429 responses carrying `insufficient_quota`,
  `credit_balance_exhausted`, `organization_spend_limit_exceeded`,
  `project_spend_limit_exceeded`, or `organization_usage_limit_exceeded` are no
  longer misreported as rate-limited and are excluded from the Retry-After
  retry (codes list is overridable). See the 1.4 upgrade note.
- `TextDelta::join()` for merged step texts; resumed turns rebuild their
  content from merged steps (`DatabaseConversationStore`).
- `TextGenerationLoop::pausedForApproval()`: faked agents resume tool
  approvals when the history is actually paused for approval.
- Tool failure recovery: tool throws are wrapped in the internal
  `ToolExecutionException`, reported, and returned to the model as failed
  `ToolResult`s instead of ending the run. Listeners that rethrow still fail
  the run with the original exception.
- Stored message IDs (`messageId`, `userMessageId`) are now also reported in
  `RUN_FINISHED` metadata (`AgentUserInteractionProtocol`).

### Fixed

- OpenAI and Azure batch embeddings are returned in input order even when the
  response is shuffled (`sortBy('index', SORT_ASC)`).
- Credit pattern matching is skipped when the error message is not a string.
- Hosts with a trailing dot are pinned as written in the URL
  (`Files\UntrustedUrl`).
- Anthropic messages ending with `end_turn` that still hold `tool_use` blocks
  now run the tool calls.

### Changed

- Anthropic `pause_turn` continuations seed streaming state from the paused
  replay blocks, and `TextDelta::combine` starts a new paragraph only after a
  step that ran tools.
- Refreshed Mistral model defaults (`mistral-large-4-0`,
  `voxtral-mini-tts-3-0`, `voxtral-mini-transcribe-3-0`, plus the
  `config/ai.php` env defaults).

## [2.1.0]

### Added

- Structured output format merging for provider output options, so provider-specific
  output settings combine with the agent's response format instead of replacing it.
- Image attachments for classification with OpenAI, TypeSafe, and OpenRouter:
  `Classification` questions can now carry images resolved through
  `ResolvesInlineImagesTrait` (`PendingClassification`, `ClassificationPrompt`).
- Stop middleware to end agent runs early with a cancelled finish.
- Single retry after a short `Retry-After` delay before failing over
  (`HandlesFailoverErrorsTrait`), instead of immediately moving to the next provider.
- Classification support in the OpenAI-compatible provider
  (`OpenAiCompatibleProvider`, `OpenAiCompatibleClassificationGateway`).
- Failure events for model operations: `ImageFailed`, `AudioFailed`,
  `TranscriptionFailed`, `EmbeddingsFailed`, `RerankingFailed`,
  `ClassificationFailed`, `StoringFileFailed`, `AddingFileToStoreFailed`,
  `CreatingStoreFailed`, and `RemovingFileFromStoreFailed`, dispatched from the
  matching provider traits.
- MariaDB native vector support in the `similarTo` finder
  (`VectorSearchBehavior`), alongside the existing PostgreSQL `pgvector` support.
- Stop predicate for streaming provider responses: the transport read aborts with
  `StoppedException` (`StoppableStream`, `WrapsStoppableStreamsTrait`), the stream
  closes, `StreamableAgentResponse` is marked stopped, and stopped runs never fail
  over. Bedrock is out of scope.
- Raw HTTP responses on file responses: `FileResponse` and `StoredFileResponse`
  expose the provider response through `HasRawResponseTrait`.
- Metadata filters for Gemini file search (`GeminiProvider`).
- Size limit for remote files (`Files\UntrustedUrl`).
- Text separator for Anthropic server-tool text in parsed and streamed responses
  (`ParsesTextResponsesTrait`, `HandlesTextStreamingTrait`).
- Node budget for shared-reference expansion in schema normalization
  (`SchemaNormalizer`).
- OpenRouter reasoning details replayed with tool calls in follow-up requests.

### Fixed

- Parse Bedrock structured output from JSON answers for models that reject forced
  tool choice.
- `CopyFile` no longer copies directories and reports missing sources.
- Handle terminal Responses API stream errors and incomplete finish reasons.
- Omit embedding dimensions for Ollama native model dimensions (`OllamaProvider`).
- Decode structured output with unescaped quotes in object keys
  (`DecodesStructuredOutputTrait`).
- Structured sub-agents now run unstreamed when the parent streams (`AgentTool`).
- Keep the call ID on failed-turn interrupted tool results
  (`DatabaseConversationStore`).
- Escape HTML in image responses (`ImageResponse`).
- Thought ordering in rebuilt Gemini turns (`MapsMessagesTrait`).

### Changed

- OpenRouter images are now generated through the images endpoint
  (`OpenRouterProvider`, `OpenRouterGateway`).
- Refreshed Anthropic model defaults; sampling parameters are skipped on new models.
- File and store IDs are now encoded as a single URL path segment
  (`EncodesResourceIdsTrait` across the Anthropic/OpenAI/OpenRouter file gateways
  and the OpenAI/Gemini store gateways; Azure inherits).
- Structured prompt returns are now narrowed (`GeneratesTextTrait`,
  `PromptableTrait`, `functions.php`, Bedrock text gateway).
- Queue callbacks are now packed via the shared `QueuedCallback` format:
  `PendingDispatch::packCallback()` delegates to
  `Crustum\Queue\Processor\QueuedCallback::pack()`.
- The plugin manifest is now optional: `crustum/plugin-manifest` moved to a dev
  dependency (plus a `suggest` entry), the manifest lives in the standalone
  `AiManifest` provider, and `AiPlugin` no longer implements `ManifestInterface`.
- The queue job processor now extends the shared `CallbackProcessor` and requires
  `crustum/cakephp-queue` ^1.4.
- Allow `symfony/http-foundation` `^6.0 || ^7.0 || ^8.0` (was `^6.0`). Installing 8.x also requires a `crustum/broadcasting` release that allows it.
- Require `league/flysystem-local` `^3.35` (was `^3.36`).


## [2.0.0]

### Added

- Initial Release
