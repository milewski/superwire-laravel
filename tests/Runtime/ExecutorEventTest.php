<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime;

use Superwire\Laravel\Data\Events\AgentCompletedEvent;
use Superwire\Laravel\Data\Events\AgentFileCreatedEvent;
use Superwire\Laravel\Data\Events\AgentFileDeletedEvent;
use Superwire\Laravel\Data\Events\AgentLoopCompletedEvent;
use Superwire\Laravel\Data\Events\AgentLoopStartedEvent;
use Superwire\Laravel\Data\Events\AgentStartedEvent;
use Superwire\Laravel\Data\Events\ContextCompactionCompletedEvent;
use Superwire\Laravel\Data\Events\ContextCompactionFailedEvent;
use Superwire\Laravel\Data\Events\ContextCompactionStartedEvent;
use Superwire\Laravel\Data\Events\McpCallCompletedEvent;
use Superwire\Laravel\Data\Events\McpCallFailedEvent;
use Superwire\Laravel\Data\Events\McpCallStartedEvent;
use Superwire\Laravel\Data\Events\McpToolSchemaFetchCompletedEvent;
use Superwire\Laravel\Data\Events\McpToolSchemaFetchFailedEvent;
use Superwire\Laravel\Data\Events\McpToolSchemaFetchStartedEvent;
use Superwire\Laravel\Data\Events\McpToolValidationCompletedEvent;
use Superwire\Laravel\Data\Events\McpToolValidationFailedEvent;
use Superwire\Laravel\Data\Events\McpToolValidationStartedEvent;
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
    public function test_it_supports_all_executor_event_kinds(): void
    {
        $eventKindValues = array_map(
            static fn (ExecutorEventKind $executorEventKind): string => $executorEventKind->value,
            ExecutorEventKind::cases(),
        );

        $this->assertSame([
            'workflow_started',
            'workflow_planned',
            'agent_loop_started',
            'agent_loop_completed',
            'context_compaction_started',
            'context_compaction_completed',
            'context_compaction_failed',
            'agent_file_created',
            'agent_file_deleted',
            'agent_started',
            'agent_completed',
            'tool_call_started',
            'tool_call_failed',
            'tool_call_completed',
            'mcp_tool_schema_fetch_started',
            'mcp_tool_schema_fetch_failed',
            'mcp_tool_schema_fetch_completed',
            'mcp_tool_validation_started',
            'mcp_tool_validation_failed',
            'mcp_tool_validation_completed',
            'mcp_call_started',
            'mcp_call_failed',
            'mcp_call_completed',
            'workflow_completed',
            'workflow_failed',
        ], $eventKindValues);
    }

    public function test_it_creates_context_compaction_events(): void
    {
        $started = ExecutorEvent::fromArray([
            'kind' => 'context_compaction_started',
            'agent_name' => 'summarize',
            'data' => [ 'model' => 'qwen-flash', 'source_agent_name' => 'research' ],
        ]);
        $completed = ExecutorEvent::fromArray([
            'kind' => 'context_compaction_completed',
            'agent_name' => 'summarize',
            'data' => [ 'output' => 'compact summary', 'duration_ms' => 12 ],
        ]);
        $failed = ExecutorEvent::fromArray([
            'kind' => 'context_compaction_failed',
            'agent_name' => 'summarize',
            'message' => 'failed to compact',
            'data' => [ 'duration_ms' => 10 ],
        ]);

        $this->assertSame(ExecutorEventKind::ContextCompactionStarted, $started->kind);
        $this->assertInstanceOf(ContextCompactionStartedEvent::class, $started->event);
        $this->assertSame('qwen-flash', $started->event->model);
        $this->assertSame('research', $started->event->sourceAgentName);

        $this->assertSame(ExecutorEventKind::ContextCompactionCompleted, $completed->kind);
        $this->assertInstanceOf(ContextCompactionCompletedEvent::class, $completed->event);
        $this->assertSame('compact summary', $completed->event->output);
        $this->assertSame(12, $completed->event->durationMs);

        $this->assertSame(ExecutorEventKind::ContextCompactionFailed, $failed->kind);
        $this->assertInstanceOf(ContextCompactionFailedEvent::class, $failed->event);
        $this->assertSame('failed to compact', $failed->event->message);
        $this->assertSame(10, $failed->event->durationMs);
    }

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
                'steps' => [
                    [
                        'type' => 'workflow_dynamic',
                        'calls' => [
                            [
                                'operation' => 'call',
                                'target_name' => 'video_recording_answers',
                                'server_name' => 'local',
                                'item_name' => 'fetch_qualitative_question_answers',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(ExecutorEventKind::WorkflowPlanned, $event->kind);
        $this->assertInstanceOf(WorkflowPlannedEvent::class, $event->event);
        $this->assertSame([ 'analyzer', 'aggregator' ], $event->event->agentExecutionOrder);
        $this->assertCount(1, $event->event->mcpImports);
        $this->assertSame('res', $event->event->mcpImports[ 0 ]->name);
        $this->assertSame('video_recording_answers', $event->event->steps[ 0 ][ 'calls' ][ 0 ][ 'target_name' ]);
    }

    public function test_it_creates_agent_started_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'agent_started',
            'agent_name' => 'analyzer',
            'data' => [ 'model' => 'gpt-4', 'tools' => [ 'tool_a', 'tool_b' ], 'iteration_index' => 2 ],
        ]);

        $this->assertSame(ExecutorEventKind::AgentStarted, $event->kind);
        $this->assertSame('analyzer', $event->agentName);
        $this->assertInstanceOf(AgentStartedEvent::class, $event->event);
        $this->assertSame('gpt-4', $event->event->model);
        $this->assertSame([ 'tool_a', 'tool_b' ], $event->event->tools);
        $this->assertSame(2, $event->event->iterationIndex);
    }

    public function test_it_creates_agent_file_events(): void
    {
        $created = ExecutorEvent::fromArray([
            'kind' => 'agent_file_created',
            'agent_name' => 'reviewer',
            'data' => [
                'file_id' => 'file-fe-test',
                'filename' => 'example.json',
                'purpose' => 'file-extract',
                'bytes' => 19,
            ],
        ]);
        $deleted = ExecutorEvent::fromArray([
            'kind' => 'agent_file_deleted',
            'agent_name' => 'reviewer',
            'data' => [
                'file_id' => 'file-fe-test',
                'filename' => 'example.json',
                'purpose' => 'file-extract',
            ],
        ]);

        $this->assertSame(ExecutorEventKind::AgentFileCreated, $created->kind);
        $this->assertSame('reviewer', $created->agentName);
        $this->assertInstanceOf(AgentFileCreatedEvent::class, $created->event);
        $this->assertSame('file-fe-test', $created->event->fileId);
        $this->assertSame('example.json', $created->event->filename);
        $this->assertSame('file-extract', $created->event->purpose);
        $this->assertSame(19, $created->event->bytes);
        $this->assertSame([
            'kind' => 'agent_file_created',
            'agent_name' => 'reviewer',
            'data' => [
                'file_id' => 'file-fe-test',
                'filename' => 'example.json',
                'purpose' => 'file-extract',
                'bytes' => 19,
            ],
        ], $created->toArray());

        $this->assertSame(ExecutorEventKind::AgentFileDeleted, $deleted->kind);
        $this->assertSame('reviewer', $deleted->agentName);
        $this->assertInstanceOf(AgentFileDeletedEvent::class, $deleted->event);
        $this->assertSame('file-fe-test', $deleted->event->fileId);
        $this->assertSame('example.json', $deleted->event->filename);
        $this->assertSame('file-extract', $deleted->event->purpose);
    }

    public function test_it_creates_agent_loop_started_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'agent_loop_started',
            'agent_name' => 'writer',
            'data' => [
                'iteration_count' => 2,
                'iterations' => [
                    [ 'iteration_index' => 0, 'bindings' => [ 'item' => 'a' ] ],
                    [ 'iteration_index' => 1, 'bindings' => [ 'item' => 'b' ] ],
                ],
            ],
        ]);

        $this->assertSame(ExecutorEventKind::AgentLoopStarted, $event->kind);
        $this->assertSame('writer', $event->agentName);
        $this->assertInstanceOf(AgentLoopStartedEvent::class, $event->event);
        $this->assertSame(2, $event->event->iterationCount);
        $this->assertSame('a', $event->event->iterations[ 0 ][ 'bindings' ][ 'item' ]);
    }

    public function test_it_creates_agent_loop_completed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'agent_loop_completed',
            'agent_name' => 'writer',
            'data' => [
                'output' => [ [ 'value' => 'a' ], [ 'value' => 'b' ] ],
                'duration_ms' => 42,
                'iteration_count' => 2,
            ],
        ]);

        $this->assertSame(ExecutorEventKind::AgentLoopCompleted, $event->kind);
        $this->assertSame('writer', $event->agentName);
        $this->assertInstanceOf(AgentLoopCompletedEvent::class, $event->event);
        $this->assertSame([ [ 'value' => 'a' ], [ 'value' => 'b' ] ], $event->event->output);
        $this->assertSame(42, $event->event->durationMs);
        $this->assertSame(2, $event->event->iterationCount);
        $this->assertSame([ [ 'value' => 'a' ], [ 'value' => 'b' ] ], $event->output());
    }

    public function test_it_creates_agent_completed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'agent_completed',
            'agent_name' => 'analyzer',
            'data' => [ 'output' => [ 'summary' => 'done' ], 'duration_ms' => 24, 'iteration_index' => 1, 'cache_hit' => true ],
        ]);

        $this->assertSame(ExecutorEventKind::AgentCompleted, $event->kind);
        $this->assertInstanceOf(AgentCompletedEvent::class, $event->event);
        $this->assertSame([ 'summary' => 'done' ], $event->event->output);
        $this->assertSame(24, $event->event->durationMs);
        $this->assertSame(1, $event->event->iterationIndex);
        $this->assertTrue($event->event->cacheHit);
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

    public function test_it_creates_mcp_tool_schema_fetch_started_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_tool_schema_fetch_started',
            'data' => [ 'server_name' => 'filesystem' ],
        ]);

        $this->assertSame(ExecutorEventKind::McpToolSchemaFetchStarted, $event->kind);
        $this->assertInstanceOf(McpToolSchemaFetchStartedEvent::class, $event->event);
        $this->assertSame('filesystem', $event->event->serverName);
        $this->assertSame([
            'kind' => 'mcp_tool_schema_fetch_started',
            'data' => [ 'server_name' => 'filesystem' ],
        ], $event->toArray());
    }

    public function test_it_creates_mcp_tool_schema_fetch_completed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_tool_schema_fetch_completed',
            'data' => [ 'server_name' => 'filesystem', 'tool_count' => 3, 'duration_ms' => 42 ],
        ]);

        $this->assertSame(ExecutorEventKind::McpToolSchemaFetchCompleted, $event->kind);
        $this->assertInstanceOf(McpToolSchemaFetchCompletedEvent::class, $event->event);
        $this->assertSame('filesystem', $event->event->serverName);
        $this->assertSame(3, $event->event->toolCount);
        $this->assertSame(42, $event->event->durationMs);
    }

    public function test_it_creates_mcp_tool_schema_fetch_failed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_tool_schema_fetch_failed',
            'data' => [ 'server_name' => 'filesystem', 'error' => [ 'message' => 'timeout' ], 'duration_ms' => 42 ],
        ]);

        $this->assertSame(ExecutorEventKind::McpToolSchemaFetchFailed, $event->kind);
        $this->assertInstanceOf(McpToolSchemaFetchFailedEvent::class, $event->event);
        $this->assertSame('filesystem', $event->event->serverName);
        $this->assertSame([ 'message' => 'timeout' ], $event->event->error);
        $this->assertSame(42, $event->event->durationMs);
    }

    public function test_it_creates_mcp_tool_validation_started_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_tool_validation_started',
            'agent_name' => 'analyzer',
            'data' => [
                'tool_name' => 'search',
                'arguments' => [ 'query' => 'superwire' ],
                'input_schema' => [ 'type' => 'object' ],
            ],
        ]);

        $this->assertSame(ExecutorEventKind::McpToolValidationStarted, $event->kind);
        $this->assertSame('analyzer', $event->agentName);
        $this->assertInstanceOf(McpToolValidationStartedEvent::class, $event->event);
        $this->assertSame('search', $event->event->toolName);
        $this->assertSame([ 'query' => 'superwire' ], $event->event->arguments);
        $this->assertSame([ 'type' => 'object' ], $event->event->inputSchema);
    }

    public function test_it_creates_mcp_tool_validation_started_event_from_params_alias(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_tool_validation_started',
            'data' => [ 'tool_name' => 'search', 'params' => [ 'query' => 'superwire' ] ],
        ]);

        $this->assertSame([ 'query' => 'superwire' ], $event->event->arguments);
    }

    public function test_it_creates_mcp_tool_validation_completed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_tool_validation_completed',
            'agent_name' => 'analyzer',
            'data' => [ 'tool_name' => 'search', 'duration_ms' => 12 ],
        ]);

        $this->assertSame(ExecutorEventKind::McpToolValidationCompleted, $event->kind);
        $this->assertSame('analyzer', $event->agentName);
        $this->assertInstanceOf(McpToolValidationCompletedEvent::class, $event->event);
        $this->assertSame('search', $event->event->toolName);
        $this->assertSame(12, $event->event->durationMs);
    }

    public function test_it_creates_mcp_tool_validation_failed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_tool_validation_failed',
            'agent_name' => 'analyzer',
            'data' => [ 'tool_name' => 'search', 'error' => 'invalid arguments', 'duration_ms' => 12 ],
        ]);

        $this->assertSame(ExecutorEventKind::McpToolValidationFailed, $event->kind);
        $this->assertSame('analyzer', $event->agentName);
        $this->assertInstanceOf(McpToolValidationFailedEvent::class, $event->event);
        $this->assertSame('search', $event->event->toolName);
        $this->assertSame('invalid arguments', $event->event->error);
        $this->assertSame(12, $event->event->durationMs);
    }

    public function test_it_creates_mcp_call_started_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_call_started',
            'data' => [
                'operation' => 'call',
                'target_name' => 'video_recording_answers',
                'server_name' => 'local',
                'item_name' => 'fetch_qualitative_question_answers',
                'params' => [ 'task_types' => [ 'video_recording' ] ],
                'input_schema' => [ 'type' => 'object' ],
            ],
        ]);

        $this->assertSame(ExecutorEventKind::McpCallStarted, $event->kind);
        $this->assertInstanceOf(McpCallStartedEvent::class, $event->event);
        $this->assertSame('call', $event->event->operation);
        $this->assertSame('video_recording_answers', $event->event->targetName);
        $this->assertSame('local', $event->event->serverName);
        $this->assertSame('fetch_qualitative_question_answers', $event->event->itemName);
        $this->assertSame([ 'task_types' => [ 'video_recording' ] ], $event->event->arguments);
        $this->assertSame([ 'type' => 'object' ], $event->event->inputSchema);
    }

    public function test_it_creates_mcp_call_completed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_call_completed',
            'data' => [
                'operation' => 'call',
                'target_name' => 'data',
                'server_name' => 'local',
                'item_name' => 'fetch_data',
                'arguments' => [ 'id' => 1 ],
                'result' => [ 'items' => [] ],
                'raw_result' => [ 'content' => [] ],
                'duration_ms' => 12,
            ],
        ]);

        $this->assertSame(ExecutorEventKind::McpCallCompleted, $event->kind);
        $this->assertInstanceOf(McpCallCompletedEvent::class, $event->event);
        $this->assertSame([ 'items' => [] ], $event->event->result);
        $this->assertSame('fetch_data', $event->event->itemName);
        $this->assertSame([ 'id' => 1 ], $event->event->arguments);
        $this->assertSame([ 'content' => [] ], $event->event->rawResult);
        $this->assertSame(12, $event->event->durationMs);
    }

    public function test_it_creates_mcp_call_failed_event(): void
    {
        $event = ExecutorEvent::fromArray([
            'kind' => 'mcp_call_failed',
            'data' => [
                'operation' => 'call',
                'target_name' => 'data',
                'server_name' => 'local',
                'item_name' => 'fetch_data',
                'params' => [ 'id' => 1 ],
                'error' => 'not found',
                'duration_ms' => 12,
            ],
        ]);

        $this->assertSame(ExecutorEventKind::McpCallFailed, $event->kind);
        $this->assertInstanceOf(McpCallFailedEvent::class, $event->event);
        $this->assertSame('not found', $event->event->error);
        $this->assertSame('fetch_data', $event->event->itemName);
        $this->assertSame([ 'id' => 1 ], $event->event->arguments);
        $this->assertSame(12, $event->event->durationMs);
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
