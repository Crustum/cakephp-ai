<?php
declare(strict_types=1);

use Cake\Command\Command;

it('can create an agent class', function (): void {
    $this->exec('bake agent Test');

    $this->assertExitCode(Command::CODE_SUCCESS);
    expect(aiBakeClassPath('Agents/TestAgent.php'))->toBeFile();
});

it('can create a structured agent class', function (): void {
    $this->exec('bake agent Structured --structured');

    $this->assertExitCode(Command::CODE_SUCCESS);
    expect(aiBakeClassPath('Agents/StructuredAgent.php'))->toBeFile();

    $content = (string)file_get_contents(aiBakeClassPath('Agents/StructuredAgent.php'));

    expect($content)->toContain('HasStructuredOutput');
});

it('has bake templates available from the plugin', function (): void {
    expect(aiBakeTemplatePath('Agent', 'agent'))->toBeFile()
        ->and(aiBakeTemplatePath('Agent', 'structured_agent'))->toBeFile();
});

it('respects force flag for existing files', function (): void {
    $this->exec('bake agent Test');
    $this->assertExitCode(Command::CODE_SUCCESS);

    $this->exec('bake agent Test --force');
    $this->assertExitCode(Command::CODE_SUCCESS);

    expect(aiBakeClassPath('Agents/TestAgent.php'))->toBeFile();
});
