<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime;

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Response;
use Superwire\Laravel\Enums\ExecutorEventKind;
use Superwire\Laravel\Runtime\SseResponse;
use Superwire\Laravel\Tests\TestCase;

final class SseResponseTest extends TestCase
{
    private function createSseResponse(string $body): Response
    {
        $psrResponse = new GuzzleResponse(200, [ 'Content-Type' => 'text/event-stream' ], $body);

        return new Response($psrResponse);
    }

    public function test_it_parses_single_sse_event(): void
    {
        $response = $this->createSseResponse("data: {\"kind\":\"workflow_started\"}\n\n");
        $events = iterator_to_array(SseResponse::parse($response));

        $this->assertCount(1, $events);
        $this->assertSame(ExecutorEventKind::WorkflowStarted, $events[ 0 ]->kind);
    }

    public function test_it_parses_multiple_sse_events(): void
    {
        $sseData = implode("\n\n", [
            'data: {"kind":"workflow_started"}',
            'data: {"kind":"agent_started","agent_name":"analyzer","data":{"model":"gpt-4","tools":[]}}',
            'data: {"kind":"agent_completed","agent_name":"analyzer","data":{"output":"result"}}',
            'data: {"kind":"workflow_completed","data":{"output":{"summary":"done"}}}',
        ]) . "\n\n";

        $response = $this->createSseResponse($sseData);
        $events = iterator_to_array(SseResponse::parse($response));

        $this->assertCount(4, $events);
        $this->assertSame(ExecutorEventKind::WorkflowStarted, $events[ 0 ]->kind);
        $this->assertSame(ExecutorEventKind::AgentStarted, $events[ 1 ]->kind);
        $this->assertSame('analyzer', $events[ 1 ]->agentName);
        $this->assertSame(ExecutorEventKind::AgentCompleted, $events[ 2 ]->kind);
        $this->assertSame(ExecutorEventKind::WorkflowCompleted, $events[ 3 ]->kind);
    }

    public function test_it_handles_empty_data_lines(): void
    {
        $response = $this->createSseResponse("\n\ndata: {\"kind\":\"workflow_started\"}\n\n");
        $events = iterator_to_array(SseResponse::parse($response));

        $this->assertCount(1, $events);
        $this->assertSame(ExecutorEventKind::WorkflowStarted, $events[ 0 ]->kind);
    }

    public function test_it_skips_invalid_json(): void
    {
        $response = $this->createSseResponse("data: not-json\n\ndata: {\"kind\":\"workflow_started\"}\n\n");
        $events = iterator_to_array(SseResponse::parse($response));

        $this->assertCount(1, $events);
        $this->assertSame(ExecutorEventKind::WorkflowStarted, $events[ 0 ]->kind);
    }

    public function test_it_handles_events_with_no_data(): void
    {
        $response = $this->createSseResponse("\n\ndata: {\"kind\":\"workflow_started\"}\n\n");
        $events = iterator_to_array(SseResponse::parse($response));

        $this->assertCount(1, $events);
    }
}
