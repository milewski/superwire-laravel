<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use InvalidArgumentException;
use Superwire\Laravel\Tools\AbstractTool;

final class ToolScopeRegistry
{
    private array $tools = [];

    public function register(AbstractTool $tool, BoundToolDefinition $binding): void
    {
        $this->tools[ $binding->runId ][ $binding->agentName ][ $binding->definition->name ] = new ScopedTool(
            binding: $binding,
            tool: $tool,
        );
    }

    public function get(string $runId, string $agentName, string $toolName): ScopedTool
    {
        return $this->tools[ $runId ][ $agentName ][ $toolName ]
            ?? throw new InvalidArgumentException(sprintf('Tool `%s` is not available for agent `%s` in workflow run `%s`.', $toolName, $agentName, $runId));
    }

    public function forget(string $runId): void
    {
        unset($this->tools[ $runId ]);
    }
}
