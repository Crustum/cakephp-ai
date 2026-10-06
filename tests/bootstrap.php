<?php
declare(strict_types=1);

$findRoot = function (): string {
    $root = dirname(__DIR__);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    $root = dirname(__DIR__, 2);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    $root = dirname(__DIR__, 3);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    return dirname(__DIR__);
};

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

define('ROOT', $findRoot());
define('APP_DIR', 'TestApp');
define('WEBROOT_DIR', 'webroot');
define('APP', ROOT . DS . 'tests' . DS . 'TestApp' . DS);
define('CONFIG', ROOT . DS . 'tests' . DS . 'TestApp' . DS . 'config' . DS);
define('WWW_ROOT', ROOT . DS . WEBROOT_DIR . DS);
define('TESTS', ROOT . DS . 'tests' . DS);
define('TMP', ROOT . DS . 'tmp' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('SESSIONS', TMP . 'sessions' . DS);
define('CAKE_CORE_INCLUDE_PATH', ROOT . '/vendor/cakephp/cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);

require ROOT . '/vendor/cakephp/cakephp/src/functions.php';
$autoloader = require ROOT . '/vendor/autoload.php';

$crustumQueuePath = ROOT . '/../CrustumQueue/src/';
if (is_dir($crustumQueuePath)) {
    $autoloader->addPsr4('Crustum\\Queue\\', $crustumQueuePath);
}

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\Core\Plugin;
use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;
use Cake\Queue\QueueManager;
use Cake\TestSuite\Fixture\SchemaLoader;
use Cake\TestSuite\Fixture\TransactionStrategy;
use Crustum\Ai\Ai;
use Crustum\Ai\AiPlugin;
use Crustum\Ai\Providers\ElevenLabsProvider;
use Crustum\Ai\Providers\OpenAiCompatibleProvider;
use Crustum\Ai\Providers\OpenRouterProvider;
use Crustum\Ai\Queue\AiJobProcessor;
use Crustum\Ai\Queue\AiQueue;
use Crustum\Queue\ContainerRegistry;

function ensureDirectoryExists(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

ensureDirectoryExists(TMP . 'cache/models');
ensureDirectoryExists(TMP . 'cache/persistent');
ensureDirectoryExists(TMP . 'cache/views');
ensureDirectoryExists(TMP . 'sessions');
ensureDirectoryExists(TMP . 'tests');
ensureDirectoryExists(LOGS);

Configure::write('App', [
    'namespace' => 'TestApp',
    'encoding' => 'UTF-8',
]);
Configure::write('App.paths.templates', [APP . 'templates' . DS]);
Configure::write('debug', true);

// Roll fixture tables back per test instead of truncating them after every test.
Configure::write('TestSuite.fixtureStrategy', TransactionStrategy::class);

Cache::setConfig('_cake_core_', [
    'className' => 'File',
    'path' => CACHE,
    'prefix' => 'ai_test_core_',
]);
Cache::setConfig('_cake_translations_', [
    'className' => 'File',
    'path' => CACHE . 'persistent/',
    'prefix' => 'ai_test_translations_',
    'serialize' => true,
    'duration' => '+10 seconds',
]);
Cache::setConfig('_cake_model_', [
    'className' => 'File',
    'path' => CACHE . 'models/',
    'prefix' => 'ai_test_model_',
    'serialize' => 'File',
    'duration' => '+10 seconds',
]);

// Schema is rebuilt from tests/schema.php on every run, so drop stale ORM
// metadata first — otherwise a column rename keeps selecting old columns.
Cache::clear('_cake_model_');

if (!getenv('db_dsn')) {
    putenv('db_dsn=sqlite:///:memory:');
}

ConnectionManager::setConfig('test', [
    'url' => getenv('db_dsn'),
    'timezone' => 'UTC',
]);

ConnectionManager::alias('test', 'default');

// Test connections follow Cake's `test_` prefix convention so the PHPUnit
// fixture extension's addTestAliases() resolves `secondary` to this connection.
ConnectionManager::setConfig('test_secondary', [
    'className' => Connection::class,
    'driver' => Sqlite::class,
    'database' => ':memory:',
    'encoding' => 'utf8',
    'timezone' => 'UTC',
    'cacheMetadata' => false,
    'quoteIdentifiers' => false,
]);

ConnectionManager::alias('test_secondary', 'secondary');

$aiPlugin = new AiPlugin([
    'path' => dirname(__DIR__) . DS,
]);
Plugin::getCollection()->add($aiPlugin);
Configure::write('plugins', [
    'Crustum/Ai' => dirname(__DIR__) . DS,
]);

$container = new Container();
$aiPlugin->services($container);
Ai::setContainer($container);

QueueManager::setConfig('default', ['url' => 'null:']);
ContainerRegistry::setInstance($container);

Configure::load('Crustum/Ai.ai', 'default');
Configure::write('Ai.conversations.connection', 'test');
Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');

// Dedicated Ai queue (names from Ai.queue config, defaults `ai`).
QueueManager::setConfig(AiQueue::connection(), [
    'url' => 'null:',
    'queue' => AiQueue::queue(),
    'processor' => AiJobProcessor::class,
]);

$providers = Configure::read('Ai.providers') ?? [];

foreach (['anthropic', 'gemini', 'groq'] as $providerName) {
    if (!isset($providers[$providerName])) {
        $className = $providerName === 'gemini'
            ? OpenRouterProvider::class
            : OpenAiCompatibleProvider::class;

        $models = [
            'text' => [
                'default' => 'test-model',
            ],
        ];

        if ($providerName === 'gemini') {
            $models['image'] = [
                'default' => 'test-image-model',
            ];
        }

        $providers[$providerName] = [
            'className' => $className,
            'name' => $providerName,
            'driver' => $providerName,
            'url' => 'https://api.example.com/' . $providerName . '/v1',
            'key' => 'test-key',
            'models' => $models,
        ];
    }
}

Configure::write('Ai.providers', $providers);

if (!isset($providers['eleven'])) {
    Configure::write('Ai.providers.eleven', [
        'className' => ElevenLabsProvider::class,
        'name' => 'eleven',
        'driver' => 'eleven',
        'url' => 'https://api.elevenlabs.io/v1/',
        'key' => 'test-key',
        'models' => [
            'audio' => [
                'default' => 'eleven_multilingual_v2',
            ],
            'transcription' => [
                'default' => 'scribe_v2',
            ],
        ],
    ]);
}

$schemaLoader = new SchemaLoader();
$schemaLoader->loadInternalFile(TESTS . 'schema.php', 'test');
$schemaLoader->loadInternalFile(TESTS . 'schema.php', 'secondary');

require CONFIG . 'bootstrap.php';
