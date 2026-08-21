<?php
declare(strict_types=1);

use Cake\Command\Command;

it('can create a tool class', function (): void {
    $this->exec('bake tool Test');

    $this->assertExitCode(Command::CODE_SUCCESS);
    expect(aiBakeClassPath('Tools/TestTool.php'))->toBeFile();
});

it('has a bake template available from the plugin', function (): void {
    expect(aiBakeTemplatePath('Tool', 'tool'))->toBeFile();
});
