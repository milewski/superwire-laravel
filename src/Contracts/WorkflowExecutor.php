<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Contracts;

use Superwire\Laravel\Data\Workflow\WorkflowDefinition;

interface WorkflowExecutor
{
    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $secrets
     * @return array<string, mixed>
     */
    public function execute(WorkflowDefinition $definition, array $inputs = [], array $secrets = []): array;
}
