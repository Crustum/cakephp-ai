# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Allow `symfony/http-foundation` `^6.0 || ^7.0 || ^8.0` (was `^6.0`). Installing 8.x also requires a `crustum/broadcasting` release that allows it.
- Require `league/flysystem-local` `^3.35` (was `^3.36`).

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


## [2.0.0]

### Added

- Initial Release
