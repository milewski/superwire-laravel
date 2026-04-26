<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use Superwire\Laravel\Data\Workflow\ToolDefinition;

final readonly class BoundToolDefinition
{
    public function __construct(
        public ToolDefinition $definition,
        public array $bounded,
        public string $runId,
        public string $agentName,
        public ?string $toolClass = null,
        public ?string $workflowPath = null,
    )
    {
    }
}
