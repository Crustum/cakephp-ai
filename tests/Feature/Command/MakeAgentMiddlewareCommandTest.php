<?php
declare(strict_types=1);

use Cake\Command\Command;

it('can create an agent middleware class', function (): void {
    $this->exec('bake agent_middleware Test');

    $this->assertExitCode(Command::CODE_SUCCESS);
    expect(aiBakeClassPath('Middleware/TestMiddleware.php'))->toBeFile()
        ->and(file_get_contents(aiBakeClassPath('Middleware/TestMiddleware.php')))->toContain('handle(PendingStep $step, Closure $next)');
});

it('has a bake template available from the plugin', function (): void {
    expect(aiBakeTemplatePath('AgentMiddleware', 'agent_middleware'))->toBeFile();
});
