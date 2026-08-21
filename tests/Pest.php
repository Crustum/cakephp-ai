<?php
declare(strict_types=1);

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Routing\Router;
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
use Crustum\Ai\TestSuite\Capture\EventCapture;
use TestApp\Application;

uses(AiTestCase::class)->in('TestCase', 'Feature', 'Integration');

uses(ConversationRelationshipTestCase::class)->in('ConversationRelationshipTest.php');
uses(DatabaseConversationStoreTestCase::class)->in('DatabaseConversationStoreTest.php');

uses(AiFlowTrait::class)->in('Feature');

uses(OpenAiHelpersTrait::class)->in('Feature/Providers/OpenAi');
uses(OllamaHelpersTrait::class)->in('Feature/Providers/Ollama');
uses(OpenRouterHelpersTrait::class)->in('Feature/Providers/OpenRouter');
uses(BedrockHelpersTrait::class)->in('Feature/Providers/Bedrock');
uses(DeepSeekHelpersTrait::class)->in('Feature/Providers/DeepSeek');
uses(MistralHelpersTrait::class)->in('Feature/Providers/Mistral');
uses(AnthropicHelpersTrait::class)->in('Feature/Providers/Anthropic');
uses(GeminiHelpersTrait::class)->in('Feature/Providers/Gemini');
uses(XaiHelpersTrait::class)->in('Feature/Providers/Xai');
uses(AzureOpenAiHelpersTrait::class)->in('Feature/Providers/AzureOpenAi');
uses(GroqHelpersTrait::class)->in('Feature/Providers/Groq');

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

uses()
    ->beforeEach(function (): void {
        if (method_exists($this, 'useCommandRunner')) {
            $this->useCommandRunner();
        }

        Router::reload();
        $this->configApplication(Application::class, [CONFIG]);
        cleanAiBakeArtifacts();
    })
    ->afterEach(function (): void {
        cleanAiBakeArtifacts();
    })
    ->in('Feature/Command');

uses()
    ->beforeEach(function (): void {
        EventCapture::ensureListening();
    })
    ->afterEach(function (): void {
        Ai::manager()->resetFakeState();
        AiHttp::stop();
    })
    ->in('Feature', 'Integration');
