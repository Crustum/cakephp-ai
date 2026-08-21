<?php
declare(strict_types=1);

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Crustum\Ai\Ai;
use Crustum\Ai\Test\Feature\Providers\Anthropic\AnthropicHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\AzureOpenAi\AzureOpenAiHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\Bedrock\BedrockHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\DeepSeek\DeepSeekHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\Gemini\GeminiHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\Groq\GroqHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\Mistral\MistralHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\Ollama\OllamaHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\OpenAi\OpenAiHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\OpenRouter\OpenRouterHelpersTrait;
use Crustum\Ai\Test\Feature\Providers\Xai\XaiHelpersTrait;
use Crustum\Ai\Test\Support\Http\AiHttp;
use Crustum\Ai\Test\TestCase\AiTestCase;
use Crustum\Ai\Test\TestCase\ConversationRelationshipTestCase;
use Crustum\Ai\Test\TestCase\DatabaseConversationStoreTestCase;
use Crustum\Ai\TestSuite\AiFlowTrait;
use TestApp\Application;

pest()->extend(AiTestCase::class)->in('TestCase', 'Feature', 'Integration');

pest()->extend(ConversationRelationshipTestCase::class)
    ->in('ConversationRelationshipTest.php');

pest()->extend(DatabaseConversationStoreTestCase::class)
    ->in('DatabaseConversationStoreTest.php');

uses(AiFlowTrait::class)->in('Feature');

pest()->use(OpenAiHelpersTrait::class)->in('Feature/Providers/OpenAi');
pest()->use(OllamaHelpersTrait::class)->in('Feature/Providers/Ollama');
pest()->use(OpenRouterHelpersTrait::class)->in('Feature/Providers/OpenRouter');
pest()->use(BedrockHelpersTrait::class)->in('Feature/Providers/Bedrock');
pest()->use(DeepSeekHelpersTrait::class)->in('Feature/Providers/DeepSeek');
pest()->use(MistralHelpersTrait::class)->in('Feature/Providers/Mistral');
pest()->use(AnthropicHelpersTrait::class)->in('Feature/Providers/Anthropic');
pest()->use(GeminiHelpersTrait::class)->in('Feature/Providers/Gemini');
pest()->use(XaiHelpersTrait::class)->in('Feature/Providers/Xai');
pest()->use(AzureOpenAiHelpersTrait::class)->in('Feature/Providers/AzureOpenAi');
pest()->use(GroqHelpersTrait::class)->in('Feature/Providers/Groq');

uses(ConsoleIntegrationTestTrait::class)->in('Feature/Command');

expect()->extend('toBeOne', fn() => $this->toBe(1));

expect()->extend('toContainStreamEventTypes', function (array $eventClasses): object {
    $types = array_map(fn($e) => $e::class, $this->value);

    foreach ($eventClasses as $class) {
        expect($types)->toContain($class);
    }

    return $this;
});

require __DIR__ . '/Support/bake_helpers.php';

pest()->beforeEach(function (): void {
    $this->configApplication(Application::class, [CONFIG]);
    cleanAiBakeArtifacts();
})->in('Feature/Command');

pest()->afterEach(function (): void {
    cleanAiBakeArtifacts();
})->in('Feature/Command');

pest()->afterEach(function (): void {
    Ai::manager()->resetFakeState();
    AiHttp::stop();
})->in('Feature', 'Integration');
