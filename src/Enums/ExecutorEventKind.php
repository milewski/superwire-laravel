<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Enums;

enum ExecutorEventKind: string
{
    case WorkflowStarted = 'workflow_started';
    case WorkflowPlanned = 'workflow_planned';
    case AgentStarted = 'agent_started';
    case AgentCompleted = 'agent_completed';
    case ToolCallStarted = 'tool_call_started';
    case ToolCallFailed = 'tool_call_failed';
    case ToolCallCompleted = 'tool_call_completed';
    case McpCallStarted = 'mcp_call_started';
    case McpCallFailed = 'mcp_call_failed';
    case McpCallCompleted = 'mcp_call_completed';
    case WorkflowCompleted = 'workflow_completed';
    case WorkflowFailed = 'workflow_failed';

    public function isTerminal(): bool
    {
        return $this === self::WorkflowCompleted || $this === self::WorkflowFailed;
    }
}
