<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Enums;

enum ExecutorEventKind: string
{
    case WorkflowStarted = 'workflow_started';
    case WorkflowPlanned = 'workflow_planned';
    case AgentLoopStarted = 'agent_loop_started';
    case AgentLoopCompleted = 'agent_loop_completed';
    case ContextCompactionStarted = 'context_compaction_started';
    case ContextCompactionCompleted = 'context_compaction_completed';
    case ContextCompactionFailed = 'context_compaction_failed';
    case AgentStarted = 'agent_started';
    case AgentCompleted = 'agent_completed';
    case ToolCallStarted = 'tool_call_started';
    case ToolCallFailed = 'tool_call_failed';
    case ToolCallCompleted = 'tool_call_completed';
    case McpToolSchemaFetchStarted = 'mcp_tool_schema_fetch_started';
    case McpToolSchemaFetchFailed = 'mcp_tool_schema_fetch_failed';
    case McpToolSchemaFetchCompleted = 'mcp_tool_schema_fetch_completed';
    case McpToolValidationStarted = 'mcp_tool_validation_started';
    case McpToolValidationFailed = 'mcp_tool_validation_failed';
    case McpToolValidationCompleted = 'mcp_tool_validation_completed';
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
