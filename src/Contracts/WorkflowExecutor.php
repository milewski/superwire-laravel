<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Contracts;

use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Enums\AgentMode;
use Superwire\Laravel\Enums\OutputStrategy;
use Superwire\Laravel\Runtime\WorkflowResult;

interface WorkflowExecutor
{
    public function execute(WorkflowDefinition $definition, array $inputs = [], array $secrets = [], array $tools = [], ?string $runId = null, ?AgentMode $agentMode = null, ?OutputStrategy $outputStrategy = null): WorkflowResult;
}
