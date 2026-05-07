<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Superwire\Laravel\Runtime\WorkflowResult;
use Superwire\Laravel\Workflow;

final class WorkflowTest extends TestCase
{
    public function test_it_creates_workflow_from_file(): void
    {
        $path = $this->writeTemporaryWorkflow('workflow test { output: string }');

        $workflow = Workflow::fromFile($path);

        $this->assertSame($path, $workflow->filePath());
        $this->assertSame(base64_encode('workflow test { output: string }'), $workflow->sourceBase64());
    }

    public function test_it_throws_for_nonexistent_file(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Workflow::fromFile('/nonexistent/path/workflow.wire');
    }

    public function test_it_creates_workflow_from_source(): void
    {
        $source = 'workflow test { output: string }';
        $workflow = Workflow::fromSource($source);

        $this->assertSame('inline', $workflow->filePath());
        $this->assertSame(base64_encode($source), $workflow->sourceBase64());
    }

    public function test_it_sets_inputs_fluently(): void
    {
        $workflow = Workflow::fromSource('test')->inputs([ 'id' => 1, 'name' => 'test' ]);

        $this->assertSame(base64_encode('test'), $workflow->sourceBase64());
    }

    public function test_it_sets_secrets_fluently(): void
    {
        $workflow = Workflow::fromSource('test')->secrets([ 'api_key' => 'sk-test' ]);

        $this->assertSame(base64_encode('test'), $workflow->sourceBase64());
    }

    public function test_it_executes_workflow_and_returns_result(): void
    {
        Http::fake([
            'localhost:3000/execute' => Http::response([
                'output' => [ 'summary' => 'Test', 'themes' => [] ],
            ]),
        ]);

        $result = Workflow::fromSource('test workflow')
            ->inputs([ 'project_id' => 1 ])
            ->secrets([ 'api_key' => 'test' ])
            ->run();

        $this->assertInstanceOf(WorkflowResult::class, $result);
        $this->assertSame([ 'summary' => 'Test', 'themes' => [] ], $result->output);
    }

    public function test_it_streams_workflow_events(): void
    {
        $sseBody = implode("\n\n", [
            'data: {"kind":"workflow_started"}',
            'data: {"kind":"workflow_completed","data":{"output":{"summary":"done"}}}',
        ]) . "\n\n";

        Http::fake([
            'localhost:3000/execute/stream' => Http::response($sseBody, 200, [ 'Content-Type' => 'text/event-stream' ]),
        ]);

        $events = iterator_to_array(
            Workflow::fromSource('test')->inputs([ 'id' => 1 ])->stream(),
        );

        $this->assertCount(2, $events);
        $this->assertSame('workflow_started', $events[ 0 ]->kind->value);
        $this->assertSame('workflow_completed', $events[ 1 ]->kind->value);
    }

    public function test_it_streams_to_result(): void
    {
        $sseBody = implode("\n\n", [
            'data: {"kind":"workflow_started"}',
            'data: {"kind":"workflow_completed","data":{"output":{"summary":"done"}}}',
        ]) . "\n\n";

        Http::fake([
            'localhost:3000/execute/stream' => Http::response($sseBody, 200, [ 'Content-Type' => 'text/event-stream' ]),
        ]);

        $result = Workflow::fromSource('test')
            ->inputs([ 'id' => 1 ])
            ->secrets([ 'key' => 'val' ])
            ->streamToResult();

        $this->assertInstanceOf(WorkflowResult::class, $result);
        $this->assertSame([ 'summary' => 'done' ], $result->output);
        $this->assertCount(2, $result->history);
    }

    public function test_workflow_is_immutable(): void
    {
        $original = Workflow::fromSource('test');
        $withInputs = $original->inputs([ 'a' => 1 ]);
        $withSecrets = $original->secrets([ 'b' => 2 ]);

        $this->assertNotSame($original, $withInputs);
        $this->assertNotSame($original, $withSecrets);
        $this->assertNotSame($withInputs, $withSecrets);
    }

    public function test_it_throws_for_nonexistent_output_class(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Workflow::fromSource('test')->mapInto('NonExistentClass');
    }

    public function test_it_maps_output_into_class_with_from_method(): void
    {
        Http::fake([
            'localhost:3000/execute' => Http::response([
                'output' => [ 'summary' => 'Test', 'themes' => [ [ 'theme' => 'a', 'times' => 1 ] ] ],
            ]),
        ]);

        $result = Workflow::fromSource('test')
            ->mapInto(StubMappedOutput::class)
            ->run();

        $this->assertInstanceOf(StubMappedOutput::class, $result->output);
        $this->assertSame('Test', $result->output->summary);
    }

    public function test_it_maps_output_into_class_with_array_constructor(): void
    {
        Http::fake([
            'localhost:3000/execute' => Http::response([
                'output' => [ 'hello', 'world' ],
            ]),
        ]);

        $result = Workflow::fromSource('test')
            ->mapInto(StubFromArrayOutput::class)
            ->run();

        $this->assertInstanceOf(StubFromArrayOutput::class, $result->output);
        $this->assertSame('hello', $result->output->first);
        $this->assertSame('world', $result->output->second);
    }

    public function test_it_returns_raw_output_when_no_mapInto(): void
    {
        Http::fake([
            'localhost:3000/execute' => Http::response([
                'output' => [ 'summary' => 'raw' ],
            ]),
        ]);

        $result = Workflow::fromSource('test')->run();

        $this->assertSame([ 'summary' => 'raw' ], $result->output);
    }
}

final class StubMappedOutput
{
    public function __construct(
        public string $summary,
        public array $themes,
    )
    {
    }

    public static function from(array $data): self
    {
        return new self(
            summary: $data[ 'summary' ],
            themes: $data[ 'themes' ] ?? [],
        );
    }
}

final class StubFromArrayOutput
{
    public function __construct(
        public string $first,
        public string $second,
    )
    {
    }
}
