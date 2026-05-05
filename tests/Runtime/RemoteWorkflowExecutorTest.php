<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;
use Superwire\Laravel\Enums\ExecutorEventKind;
use Superwire\Laravel\Runtime\RemoteWorkflowExecutor;
use Superwire\Laravel\Tests\TestCase;

final class RemoteWorkflowExecutorTest extends TestCase
{
    private RemoteWorkflowExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new RemoteWorkflowExecutor('http://localhost:3000', 300);
    }

    public function test_it_sends_execute_request_and_returns_result(): void
    {
        Http::fake([
            'localhost:3000/execute' => Http::response([
                'output' => [ 'summary' => 'Test summary', 'themes' => [] ],
            ], 200),
        ]);

        $source = base64_encode('workflow test { output: string }');
        $result = $this->executor->execute($source, [ 'project_id' => 1 ], [ 'api_key' => 'test' ]);

        $this->assertSame([ 'summary' => 'Test summary', 'themes' => [] ], $result->output);
        $this->assertSame([ 'project_id' => 1 ], $result->context[ 'input' ]);
        $this->assertSame([ 'api_key' => 'test' ], $result->context[ 'secrets' ]);
    }

    public function test_it_sends_correct_payload_structure(): void
    {
        Http::fake([
            'localhost:3000/execute' => Http::response([ 'output' => null ], 200),
        ]);

        $this->executor->execute(base64_encode('test'), [ 'id' => 1 ], [ 'key' => 'val' ]);

        Http::assertSent(function ($request): bool {

            return $request->url() === 'http://localhost:3000/execute'
                && $request->data()[ 'workflow_source_base64' ] !== null
                && $request->data()[ 'input' ] === [ 'id' => 1 ]
                && $request->data()[ 'secrets' ] === [ 'key' => 'val' ];

        });
    }

    public function test_it_throws_on_failed_execute_request(): void
    {
        Http::fake([
            'localhost:3000/execute' => Http::response('Internal Server Error', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workflow execution failed with status 500');

        $this->executor->execute(base64_encode('test'));
    }

    public function test_it_sends_empty_objects_for_empty_inputs_and_secrets(): void
    {
        Http::fake([
            'localhost:3000/execute' => Http::response([ 'output' => null ], 200),
        ]);

        $this->executor->execute(base64_encode('test'));

        Http::assertSent(function ($request): bool {

            $data = $request->data();

            return $data[ 'input' ] instanceof stdClass && $data[ 'secrets' ] instanceof stdClass;

        });
    }

    public function test_it_streams_events_from_sse_response(): void
    {
        $sseBody = implode("\n\n", [
            'data: {"kind":"workflow_started"}',
            'data: {"kind":"agent_started","agent_name":"analyzer","data":{"model":"gpt-4","tools":[]}}',
            'data: {"kind":"workflow_completed","data":{"output":{"summary":"done"}}}',
        ]) . "\n\n";

        Http::fake([
            'localhost:3000/execute/stream' => Http::response($sseBody, 200, [ 'Content-Type' => 'text/event-stream' ]),
        ]);

        $events = iterator_to_array($this->executor->executeStream(base64_encode('test'), [ 'id' => 1 ]));

        $this->assertCount(3, $events);
        $this->assertSame(ExecutorEventKind::WorkflowStarted, $events[ 0 ]->kind);
        $this->assertSame(ExecutorEventKind::AgentStarted, $events[ 1 ]->kind);
        $this->assertSame(ExecutorEventKind::WorkflowCompleted, $events[ 2 ]->kind);
    }

    public function test_it_throws_on_failed_stream_request(): void
    {
        Http::fake([
            'localhost:3000/execute/stream' => Http::response('Bad Request', 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workflow stream request failed with status 400');

        iterator_to_array($this->executor->executeStream(base64_encode('test')));
    }

    public function test_it_collects_stream_events_into_result(): void
    {
        $sseBody = implode("\n\n", [
            'data: {"kind":"workflow_started"}',
            'data: {"kind":"agent_started","agent_name":"analyzer","data":{"model":"gpt-4","tools":[]}}',
            'data: {"kind":"workflow_completed","data":{"output":{"summary":"done"}}}',
        ]) . "\n\n";

        Http::fake([
            'localhost:3000/execute/stream' => Http::response($sseBody, 200, [ 'Content-Type' => 'text/event-stream' ]),
        ]);

        $result = $this->executor->executeStreamToResult(base64_encode('test'), [ 'id' => 1 ], [ 'key' => 'val' ]);

        $this->assertSame([ 'summary' => 'done' ], $result->output);
        $this->assertCount(3, $result->history);
        $this->assertSame([ 'id' => 1 ], $result->context[ 'input' ]);
    }

    public function test_it_throws_when_stream_contains_failure_event(): void
    {
        $sseBody = implode("\n\n", [
            'data: {"kind":"workflow_started"}',
            'data: {"kind":"workflow_failed","message":"Model unavailable"}',
        ]) . "\n\n";

        Http::fake([
            'localhost:3000/execute/stream' => Http::response($sseBody, 200, [ 'Content-Type' => 'text/event-stream' ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workflow execution failed: Model unavailable');

        $this->executor->executeStreamToResult(base64_encode('test'));
    }
}
