<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime;

use Superwire\Laravel\Data\Events\AgentCompletedEvent;
use Superwire\Laravel\Data\Events\AgentStartedEvent;
use Superwire\Laravel\Data\Events\McpCallCompletedEvent;
use Superwire\Laravel\Data\Events\McpCallFailedEvent;
use Superwire\Laravel\Data\Events\McpCallStartedEvent;
use Superwire\Laravel\Data\Events\ToolCallCompletedEvent;
use Superwire\Laravel\Data\Events\ToolCallFailedEvent;
use Superwire\Laravel\Data\Events\ToolCallStartedEvent;
use Superwire\Laravel\Data\Events\WorkflowCompletedEvent;
use Superwire\Laravel\Data\Events\WorkflowFailedEvent;
use Superwire\Laravel\Data\Events\WorkflowPlannedEvent;
use Superwire\Laravel\Data\Events\WorkflowStartedEvent;
use Superwire\Laravel\Enums\ExecutorEventKind;
use Superwire\Laravel\Runtime\ExecutorEvent;
use Superwire\Laravel\Tests\TestCase;

final class ExecutorEventTest extends TestCase
{
    public function test_it_creates_workflow_started_event(): void
    {
        $event = ExecutorEvent::fromArray([ 'kind' => 'workflow_started' ]);

        $this->assertSame(ExecutorEventKind::WorkflowStarted, $event->kind);
        $this->assertNull($event->agentName);
        $this->assertInstanceOf(WorkflowStartedEvent::class, $event->event);
        $this->assertFalse($event->isTerminal());
    }

    public function test_it_creates_workflow_planned_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'workflow_planned',
            'data' => [
                'agent_execution_order' => [ 'analyzer', 'aggregator' ],
                'mcp_imports' => [
                    [ 'name' => 'res', 'kind' => 'resource', 'server_name' => 'local', 'item_name' => 'data' ],
                ],
            ],
        ]);

        $this->assertSame(ExecutorEventKind::WorkflowPlanned, $event->kind);
        $this->assertInstanceOf(WorkflowPlannedEvent::class, $event->event);
        $this->assertSame([ 'analyzer', 'aggregator' ], $event->event->agentExecutionOrder);
        $this->assertCount(1, $event->event->mcpImports);
        $this->assertSame('res', $event->event->mcpImports[ 0 ]->name);
    }

    public function test_it_creates_agent_started_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'agent_started',
            'agent_name' => 'analyzer',
            'data' => [ 'model' => 'gpt-4', 'tools' => [ 'tool_a', 'tool_b' ] ],
        ]);

        $this->assertSame(ExecutorEventKind::AgentStarted, $event->kind);
        $this->assertSame('analyzer', $event->agentName);
        $this->assertInstanceOf(AgentStartedEvent::class, $event->event);
        $this->assertSame('gpt-4', $event->event->model);
        $this->assertSame([ 'tool_a', 'tool_b' ], $event->event->tools);
    }

    public function test_it_creates_agent_completed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'agent_completed',
            'agent_name' => 'analyzer',
            'data' => [ 'output' => [ 'summary' => 'done' ] ],
        ]);

        $this->assertSame(ExecutorEventKind::AgentCompleted, $event->kind);
        $this->assertInstanceOf(AgentCompletedEvent::class, $event->event);
        $this->assertSame([ 'summary' => 'done' ], $event->event->output);
        $this->assertSame([ 'summary' => 'done' ], $event->output());
    }

    public function test_it_creates_tool_call_started_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'tool_call_started',
            'agent_name' => 'analyzer',
            'data' => [ 'tool_name' => 'fetch_data', 'arguments' => [ 'id' => 1 ] ],
        ]);

        $this->assertSame(ExecutorEventKind::ToolCallStarted, $event->kind);
        $this->assertInstanceOf(ToolCallStartedEvent::class, $event->event);
        $this->assertSame('fetch_data', $event->event->toolName);
        $this->assertSame([ 'id' => 1 ], $event->event->arguments);
    }

    public function test_it_creates_tool_call_completed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'tool_call_completed',
            'agent_name' => 'analyzer',
            'data' => [ 'tool_name' => 'fetch_data', 'result' => [ 'rows' => 5 ] ],
        ]);

        $this->assertSame(ExecutorEventKind::ToolCallCompleted, $event->kind);
        $this->assertInstanceOf(ToolCallCompletedEvent::class, $event->event);
        $this->assertSame('fetch_data', $event->event->toolName);
        $this->assertSame([ 'rows' => 5 ], $event->event->result);
    }

    public function test_it_creates_tool_call_failed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'tool_call_failed',
            'agent_name' => 'analyzer',
            'data' => [ 'tool_name' => 'fetch_data', 'error' => 'timeout' ],
        ]);

        $this->assertSame(ExecutorEventKind::ToolCallFailed, $event->kind);
        $this->assertInstanceOf(ToolCallFailedEvent::class, $event->event);
        $this->assertSame('fetch_data', $event->event->toolName);
        $this->assertSame('timeout', $event->event->error);
    }

    public function test_it_creates_mcp_call_started_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_call_started',
            'data' => [ 'operation' => 'read_resource', 'target_name' => 'data', 'arguments' => [] ],
        ]);

        $this->assertSame(ExecutorEventKind::McpCallStarted, $event->kind);
        $this->assertInstanceOf(McpCallStartedEvent::class, $event->event);
        $this->assertSame('read_resource', $event->event->operation);
        $this->assertSame('data', $event->event->targetName);
    }

    public function test_it_creates_mcp_call_completed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_call_completed',
            'data' => [ 'operation' => 'read_resource', 'target_name' => 'data', 'result' => [ 'items' => [] ] ],
        ]);

        $this->assertSame(ExecutorEventKind::McpCallCompleted, $event->kind);
        $this->assertInstanceOf(McpCallCompletedEvent::class, $event->event);
        $this->assertSame([ 'items' => [] ], $event->event->result);
    }

    public function test_it_creates_mcp_call_failed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_call_failed',
            'data' => [ 'operation' => 'read_resource', 'target_name' => 'data', 'error' => 'not found' ],
        ]);

        $this->assertSame(ExecutorEventKind::McpCallFailed, $event->kind);
        $this->assertInstanceOf(McpCallFailedEvent::class, $event->event);
        $this->assertSame('not found', $event->event->error);
    }

    public function test_it_creates_workflow_completed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'workflow_completed',
            'data' => [ 'output' => [ 'summary' => 'All done' ] ],
        ]);

        $this->assertSame(ExecutorEventKind::WorkflowCompleted, $event->kind);
        $this->assertInstanceOf(WorkflowCompletedEvent::class, $event->event);
        $this->assertTrue($event->isTerminal());
        $this->assertSame([ 'summary' => 'All done' ], $event->output());
    }

    public function test_it_creates_workflow_failed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'workflow_failed',
            'message' => 'Model unavailable',
        ]);

        $this->assertSame(ExecutorEventKind::WorkflowFailed, $event->kind);
        $this->assertInstanceOf(WorkflowFailedEvent::class, $event->event);
        $this->assertTrue($event->isTerminal());
        $this->assertSame('Model unavailable', $event->event->message);
    }

    public function test_output_returns_null_for_non_output_events(): void
    {
        $event = ExecutorEvent::fromArray([ 'kind' => 'workflow_started' ]);

        $this->assertNull($event->output());
    }

    public function test_it_serializes_to_array(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'agent_started',
            'agent_name' => 'analyzer',
            'data' => [ 'model' => 'gpt-4', 'tools' => [] ],
        ]);

        $array = $event->toArray();

        $this->assertSame('agent_started', $array[ 'kind' ]);
        $this->assertSame('analyzer', $array[ 'agent_name' ]);
        $this->assertSame([ 'model' => 'gpt-4', 'tools' => [] ], $array[ 'data' ]);
    }

    public function test_workflow_failed_serializes_message(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'workflow_failed',
            'message' => 'Something broke',
        ]);

        $array = $event->toArray();

        $this->assertSame('workflow_failed', $array[ 'kind' ]);
        $this->assertSame('Something broke', $array[ 'message' ]);
    }
}
