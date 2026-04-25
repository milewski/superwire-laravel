<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Contracts;

use Superwire\Laravel\Data\Workflow\WorkflowDefinition;

interface WorkflowCompiler
{
    public function compile(string $workflowPath): WorkflowDefinition;

    public function compileToJson(string $workflowPath): string;
}
