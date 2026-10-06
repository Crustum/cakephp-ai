<?php
declare(strict_types=1);

/**
 * AI Plugin Configuration
 *
 * This configuration file contains settings for AI providers, models, and integrations.
 * All settings can be overridden via environment variables.
 */
return [
    'Ai' => [
        /**
         * Default AI provider to use
         */
        'defaultProvider' => env('AI_DEFAULT_PROVIDER', 'openrouter'),

        /**
         * Default providers for specific capabilities
         */
        'default_for_images' => env('AI_DEFAULT_FOR_IMAGES', 'openrouter'),
        'default_for_audio' => env('AI_DEFAULT_FOR_AUDIO', 'openrouter'),
        'default_for_transcription' => env('AI_DEFAULT_FOR_TRANSCRIPTION', 'openrouter'),
        'default_for_embeddings' => env('AI_DEFAULT_FOR_EMBEDDINGS', 'openrouter'),
        'default_for_reranking' => env('AI_DEFAULT_FOR_RERANKING', 'openrouter'),
        'default_for_classification' => env('AI_DEFAULT_FOR_CLASSIFICATION', 'typesafe'),
        'default_for_stores' => env('AI_DEFAULT_FOR_STORES', 'openai'),
        'default_for_files' => env('AI_DEFAULT_FOR_FILES', 'openai'),

        /**
         * Caching configuration for embeddings.
         *
         * - cache: master switch for embeddings caching
         * - store: Cake cache config to use
         * - seconds: default TTL when cache() is called without a duration
         * - individually: cache each input's embedding under its own key
         */
        'caching' => [
            'embeddings' => [
                'cache' => env('AI_EMBEDDINGS_CACHE', false),
                'store' => env('AI_EMBEDDINGS_CACHE_STORE', 'default'),
                'seconds' => (int)env('AI_EMBEDDINGS_CACHE_SECONDS', 60 * 60 * 24 * 30),
                'individually' => env('AI_EMBEDDINGS_CACHE_INDIVIDUALLY', true),
            ],
        ],

        /**
         * Provider configurations
         *
         * Each provider requires:
         * - className: Fully qualified class name
         * - apiKey: API key for the provider
         * - baseUrl: Base URL for API requests
         * - timeout: Request timeout in seconds
         * - models: Array of model configurations
         */
        'providers' => [
            'openrouter' => [
                'className' => 'Crustum\Ai\Providers\OpenRouterProvider',
                'apiKey' => env('OPENROUTER_API_KEY'),
                'baseUrl' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
                'timeout' => (int)env('OPENROUTER_TIMEOUT', 120),
                'models' => [
                    'text' => env('OPENROUTER_TEXT_MODEL', 'anthropic/claude-sonnet-5.5'),
                    'embeddings' => [
                        'default' => env('OPENROUTER_EMBEDDINGS_MODEL', 'google/gemini-embedding-001'),
                        'dimensions' => (int)env('OPENROUTER_EMBEDDINGS_DIMENSIONS', 1536),
                    ],
                    'image' => env('OPENROUTER_IMAGE_MODEL'),
                    'audio' => env('OPENROUTER_AUDIO_MODEL'),
                    'transcription' => env('OPENROUTER_TRANSCRIPTION_MODEL'),
                ],
            ],
            'openai' => [
                'className' => 'Crustum\Ai\Providers\OpenAiProvider',
                'apiKey' => env('OPENAI_API_KEY'),
                'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
                'store' => env('OPENAI_STORE', true),
                'timeout' => (int)env('OPENAI_TIMEOUT', 120),
                'models' => [
                    'text' => [
                    'default' => env('OPENAI_TEXT_MODEL', 'gpt-6.1-sol'),
                    'cheapest' => env('OPENAI_TEXT_MODEL_CHEAPEST', 'gpt-6-luna'),
                    'smartest' => env('OPENAI_TEXT_MODEL_SMARTEST', 'gpt-6-astra'),
                    ],
                    'image' => [
                        'default' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2.5-flare'),
                    ],
                    'audio' => [
                        'default' => env('OPENAI_AUDIO_MODEL', 'gpt-4o-mini-tts'),
                    ],
                    'transcription' => [
                        'default' => env('OPENAI_TRANSCRIPTION_MODEL', 'gpt-4o-transcribe-diarize'),
                    ],
                    'embeddings' => [
                        'default' => env('OPENAI_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
                        'dimensions' => (int)env('OPENAI_EMBEDDINGS_DIMENSIONS', 1536),
                    ],
                ],
            ],
            'openai-compatible' => [
                'className' => 'Crustum\Ai\Providers\OpenAiCompatibleProvider',
                'url' => env('OPENAI_COMPATIBLE_URL'),
                'key' => env('OPENAI_COMPATIBLE_API_KEY'),
                'models' => [
                    'text' => [
                        'default' => env('OPENAI_COMPATIBLE_TEXT_MODEL'),
                    ],
                    'embeddings' => [
                        'default' => env('OPENAI_COMPATIBLE_EMBEDDINGS_MODEL'),
                        'dimensions' => (int)env('OPENAI_COMPATIBLE_EMBEDDINGS_DIMENSIONS', 1536),
                    ],
                ],
            ],
            'ollama' => [
                'className' => 'Crustum\Ai\Providers\OllamaProvider',
                'key' => env('OLLAMA_API_KEY', ''),
                'url' => env('OLLAMA_URL', 'http://localhost:11434'),
                'models' => [
                    'text' => [
                    'default' => env('OLLAMA_TEXT_MODEL', 'qwen3.5:4b'),
                    'cheapest' => env('OLLAMA_TEXT_MODEL_CHEAPEST', 'qwen3.5:0.8b'),
                    'smartest' => env('OLLAMA_TEXT_MODEL_SMARTEST', 'gemma4:cloud'),
                    ],
                    'embeddings' => [
                        'default' => env('OLLAMA_EMBEDDINGS_MODEL', 'nomic-embed-text'),
                        'dimensions' => (int)env('OLLAMA_EMBEDDINGS_DIMENSIONS', 768),
                    ],
                ],
            ],
            'bedrock' => [
                'className' => 'Crustum\Ai\Providers\BedrockProvider',
                'access_key_id' => env('AWS_ACCESS_KEY_ID'),
                'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_REGION', 'us-east-1'),
                'use_default_credential_provider' => true,
                'models' => [
                    'text' => [
                        'default' => env('BEDROCK_TEXT_MODEL', 'global.anthropic.claude-sonnet-5-5'),
                        'cheapest' => env('BEDROCK_TEXT_MODEL_CHEAPEST', 'global.anthropic.claude-haiku-4-5-20251001-v1:0'),
                        'smartest' => env('BEDROCK_TEXT_MODEL_SMARTEST', 'global.anthropic.claude-opus-5-5'),
                    ],
                    'embeddings' => [
                        'default' => env('BEDROCK_EMBEDDINGS_MODEL', 'amazon.titan-embed-text-v2:0'),
                        'dimensions' => (int)env('BEDROCK_EMBEDDINGS_DIMENSIONS', 1024),
                    ],
                    'image' => [
                        'default' => env('BEDROCK_IMAGE_MODEL', 'amazon.nova-canvas-v1:0'),
                    ],
                ],
            ],
            'deepseek' => [
                'className' => 'Crustum\Ai\Providers\DeepSeekProvider',
                'key' => env('DEEPSEEK_API_KEY'),
                'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1'),
                'models' => [
                    'text' => [
                        'default' => env('DEEPSEEK_TEXT_MODEL', 'deepseek-flash'),
                        'cheapest' => env('DEEPSEEK_TEXT_MODEL_CHEAPEST', 'deepseek-flash'),
                        'smartest' => env('DEEPSEEK_TEXT_MODEL_SMARTEST', 'deepseek-v4-pro'),
                    ],
                ],
            ],
            'mistral' => [
                'className' => 'Crustum\Ai\Providers\MistralProvider',
                'key' => env('MISTRAL_API_KEY'),
                'url' => env('MISTRAL_URL', 'https://api.mistral.ai/v1'),
                'models' => [
                    'text' => [
                        'default' => env('MISTRAL_TEXT_MODEL', 'mistral-large-2512'),
                        'cheapest' => env('MISTRAL_TEXT_MODEL_CHEAPEST', 'mistral-small-4'),
                        'smartest' => env('MISTRAL_TEXT_MODEL_SMARTEST', 'mistral-medium-3-5'),
                    ],
                    'embeddings' => [
                        'default' => env('MISTRAL_EMBEDDINGS_MODEL', 'mistral-embed-2312'),
                        'dimensions' => (int)env('MISTRAL_EMBEDDINGS_DIMENSIONS', 1024),
                    ],
                    'transcription' => [
                        'default' => env('MISTRAL_TRANSCRIPTION_MODEL', 'voxtral-mini-2602'),
                    ],
                ],
            ],
            'anthropic' => [
                'className' => 'Crustum\Ai\Providers\AnthropicProvider',
                'key' => env('ANTHROPIC_API_KEY'),
                'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
                'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
                'models' => [
                    'text' => [
                        'default' => env('ANTHROPIC_TEXT_MODEL', 'claude-sonnet-5-5'),
                        'cheapest' => env('ANTHROPIC_TEXT_MODEL_CHEAPEST', 'claude-haiku-4-5-20251001'),
                        'smartest' => env('ANTHROPIC_TEXT_MODEL_SMARTEST', 'claude-fable-5-1'),
                    ],
                ],
            ],
            'gemini' => [
                'className' => 'Crustum\Ai\Providers\GeminiProvider',
                'key' => env('GEMINI_API_KEY'),
                'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                'models' => [
                    'text' => [
                        'default' => env('GEMINI_TEXT_MODEL', 'gemini-3.8-flash'),
                        'cheapest' => env('GEMINI_TEXT_MODEL_CHEAPEST', 'gemini-3.1-flash-lite'),
                        'smartest' => env('GEMINI_TEXT_MODEL_SMARTEST', 'gemini-3.8-flash'),
                    ],
                    'image' => [
                        'default' => env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image'),
                    ],
                    'audio' => [
                        'default' => env('GEMINI_AUDIO_MODEL', 'gemini-3.8-flash-lite-tts'),
                    ],
                    'transcription' => [
                        'default' => env('GEMINI_TRANSCRIPTION_MODEL', 'gemini-3.5-transcribe'),
                    ],
                    'embeddings' => [
                        'default' => env('GEMINI_EMBEDDINGS_MODEL', 'gemini-embedding-2'),
                        'dimensions' => (int)env('GEMINI_EMBEDDINGS_DIMENSIONS', 3072),
                    ],
                ],
            ],
            'xai' => [
                'className' => 'Crustum\Ai\Providers\XaiProvider',
                'key' => env('XAI_API_KEY'),
                'url' => env('XAI_URL', 'https://api.x.ai/v1'),
                'models' => [
                    'text' => [
                        'default' => env('XAI_TEXT_MODEL', 'grok-4.7'),
                        'cheapest' => env('XAI_TEXT_MODEL_CHEAPEST', 'grok-4.20-non-reasoning'),
                        'smartest' => env('XAI_TEXT_MODEL_SMARTEST', 'grok-4.7'),
                    ],
                    'image' => [
                        'default' => env('XAI_IMAGE_MODEL', 'grok-imagine-image-2.0'),
                    ],
                ],
            ],
            'azure' => [
                'className' => 'Crustum\Ai\Providers\AzureOpenAiProvider',
                'key' => env('AZURE_OPENAI_API_KEY'),
                'url' => env('AZURE_OPENAI_URL'),
                'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
                'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-6-sol'),
                'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-2.5-flare'),
                'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
                'models' => [
                    'embeddings' => [
                        'dimensions' => (int)env('AZURE_OPENAI_EMBEDDINGS_DIMENSIONS', 1536),
                    ],
                ],
            ],
            'typesafe' => [
                'className' => 'Crustum\Ai\Providers\TypeSafeProvider',
                'key' => env('TYPESAFE_API_KEY'),
                'url' => env('TYPESAFE_URL', 'https://api.typesafe.ai/v1'),
                'models' => [
                    'classification' => [
                        'default' => env('TYPESAFE_CLASSIFICATION_MODEL', 'jev-latest'),
                    ],
                ],
            ],
            'voyageai' => [
                'className' => 'Crustum\Ai\Providers\VoyageAiProvider',
                'key' => env('VOYAGE_AI_API_KEY'),
                'url' => env('VOYAGE_AI_URL', 'https://api.voyageai.com/v1'),
                'models' => [
                    'embeddings' => [
                        'default' => env('VOYAGE_AI_EMBEDDINGS_MODEL', 'voyage-4'),
                        'dimensions' => (int)env('VOYAGE_AI_EMBEDDINGS_DIMENSIONS', 1024),
                    ],
                    'reranking' => [
                        'default' => env('VOYAGE_AI_RERANKING_MODEL', 'rerank-3'),
                    ],
                ],
            ],
            'cohere' => [
                'className' => 'Crustum\Ai\Providers\CohereProvider',
                'key' => env('COHERE_API_KEY'),
                'url' => env('COHERE_URL', 'https://api.cohere.com/v2'),
                'models' => [
                    'text' => [
                        'default' => env('COHERE_TEXT_MODEL', 'command-a-03-2025'),
                        'cheapest' => env('COHERE_TEXT_MODEL_CHEAPEST', 'command-r7b-12-2024'),
                        'smartest' => env('COHERE_TEXT_MODEL_SMARTEST', 'command-a-plus-05-2026'),
                    ],
                    'embeddings' => [
                        'default' => env('COHERE_EMBEDDINGS_MODEL', 'embed-v4.0'),
                        'dimensions' => (int)env('COHERE_EMBEDDINGS_DIMENSIONS', 1536),
                    ],
                    'reranking' => [
                        'default' => env('COHERE_RERANKING_MODEL', 'rerank-v4.0-pro'),
                    ],
                ],
            ],
            'jina' => [
                'className' => 'Crustum\Ai\Providers\JinaProvider',
                'key' => env('JINA_API_KEY'),
                'url' => env('JINA_URL', 'https://api.jina.ai/v1'),
                'models' => [
                    'embeddings' => [
                        'default' => env('JINA_EMBEDDINGS_MODEL', 'jina-embeddings-v4'),
                        'dimensions' => (int)env('JINA_EMBEDDINGS_DIMENSIONS', 2048),
                    ],
                    'reranking' => [
                        'default' => env('JINA_RERANKING_MODEL', 'jina-reranker-v3.5'),
                    ],
                ],
            ],
            'eleven' => [
                'className' => 'Crustum\Ai\Providers\ElevenLabsProvider',
                'key' => env('ELEVENLABS_API_KEY'),
                'url' => env('ELEVENLABS_URL', 'https://api.elevenlabs.io/v1'),
                'models' => [
                    'audio' => [
                        'default' => env('ELEVENLABS_AUDIO_MODEL', 'eleven_multilingual_v2'),
                    ],
                    'transcription' => [
                        'default' => env('ELEVENLABS_TRANSCRIPTION_MODEL', 'scribe_v2'),
                    ],
                ],
            ],
            'groq' => [
                'className' => 'Crustum\Ai\Providers\GroqProvider',
                'key' => env('GROQ_API_KEY'),
                'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
                'models' => [
                    'text' => [
                        'default' => env('GROQ_TEXT_MODEL', 'openai/gpt-oss-120b'),
                        'cheapest' => env('GROQ_TEXT_MODEL_CHEAPEST', 'openai/gpt-oss-20b'),
                        'smartest' => env('GROQ_TEXT_MODEL_SMARTEST', 'openai/gpt-oss-120b'),
                    ],
                ],
            ],
        ],

        /**
         * Broadcasting integration configuration
         */
        'broadcasting' => [
            'enabled' => env('AI_BROADCASTING_ENABLED', false),
            'channel' => env('AI_BROADCASTING_CHANNEL', 'ai'),
        ],

        /**
         * Queue integration configuration
         *
         * Ai jobs never share the application's `default` queue: they run
         * under a dedicated processor and must be consumed by a worker bound
         * to it. Point `connection` at a QueueManager connection configured
         * with `Crustum\Ai\Queue\AiJobProcessor`, and `queue` at the broker
         * queue that worker consumes.
         */
        'queue' => [
            'enabled' => env('AI_QUEUE_ENABLED', false),
            'connection' => env('AI_QUEUE_CONNECTION', 'ai'),
            'queue' => env('AI_QUEUE_NAME', 'ai'),
        ],

        /**
         * Local Flysystem root for filesystem tools (ReadFile, WriteFile, …).
         *
         * Named operators for Stored* use Ai.filesystem.named + FilesystemRegistry::register().
         */
        'filesystem' => [
            'root' => env('AI_FILESYSTEM_ROOT', defined('TMP') ? TMP . 'ai_storage' : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai_storage'),
            'url' => env('AI_FILESYSTEM_URL', '/storage'),
            'default' => env('AI_FILESYSTEM_DEFAULT', 'local'),
            'named' => [
                // 'local' => ['root' => TMP . 'ai_storage'],
                // Or register non-local adapters in bootstrap:
                // FilesystemRegistry::register('s3', $operator);
            ],
        ],

        /**
         * Storage configuration for conversations and files
         */
        'storage' => [
            'driver' => env('AI_STORAGE_DRIVER', 'database'),
            'table' => env('AI_STORAGE_TABLE', 'ai_conversations'),
        ],

        /**
         * Conversation behavior configuration
         */
        'conversations' => [
            'connection' => env('AI_CONVERSATIONS_CONNECTION', 'default'),
            'tables' => [
                'conversations' => env('AI_CONVERSATIONS_TABLE', 'agent_conversations'),
                'messages' => env('AI_CONVERSATIONS_MESSAGES_TABLE', 'agent_conversation_messages'),
            ],
            'generate_title' => env('AI_CONVERSATIONS_GENERATE_TITLE', true),
        ],
    ],
];
