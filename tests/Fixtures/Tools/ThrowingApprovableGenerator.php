<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Contracts\Approvable;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Request;
use Crustum\Ai\Trait\InteractsWithApprovalsTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Exception;

class ThrowingApprovableGenerator implements Approvable, Tool
{
    use InteractsWithApprovalsTrait;

    public function description(): string
    {
        return 'Generates a number, but requires human approval first and always fails.';
    }

    public function handle(Request $request): string
    {
        throw new Exception('Forced to throw exception.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
