<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Contracts;

use Superwire\Laravel\Data\Workflow\WorkflowDefinition;

interface WorkflowExecutor
{
    public function execute(WorkflowDefinition $definition, array $inputs = [], array $secrets = [], array $tools = [], ?string $runId = null, ?string $agentMode = null): array;
}
