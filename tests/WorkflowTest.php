<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests;

use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Enums\OutputStrategy;
use Superwire\Laravel\Runtime\Executor\ParallelWorkflowExecutor;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Tests\Fixtures\FakeAgentRunner;
use Superwire\Laravel\Tests\Fixtures\FakeStreamableAgentRunner;
use Superwire\Laravel\Tests\Fixtures\Tools\BoundSchemaTool;
use Superwire\Laravel\Tests\Fixtures\Tools\RetryWeatherTool;
use Superwire\Laravel\Workflow;

final class WorkflowTest extends TestCase
{
    public function test_temp_live_example(): void
    {
        if (getenv('SUPERWIRE_RUN_LIVE_TESTS') !== '1') {
            $this->markTestSkipped('Set SUPERWIRE_RUN_LIVE_TESTS=1 to run the live LLM workflow test.');
        }

        $result = Workflow::fromFile(__DIR__ . '/Stubs/example.wire')
            ->usingRequestMode()
            ->withStrategy(OutputStrategy::ToolCalling)
            ->parallel()
            ->run();

        $this->assertSame(
            expected: [
                'numbers' => [
                    [ 'number' => 1, 'number_string' => 'one' ],
                    [ 'number' => 2, 'number_string' => 'two' ],
                    [ 'number' => 3, 'number_string' => 'three' ],
                    [ 'number' => 4, 'number_string' => 'four' ],
                    [ 'number' => 5, 'number_string' => 'five' ],
                ],
            ],
            actual: $result->output,
        );
    }

    public function test_it_executes_batches_and_resolves_agent_references(): void
    {
        $runner = FakeAgentRunner::fake([
            'customer_story' => 'customer',
            'investor_story' => 'investor',
            'review' => fn (AgentInvocation $invocation): string => $invocation->prompt,
        ]);

        $result = Workflow::fromFile(__DIR__ . '/Stubs/parallel_batch.wire')->run();

        $this->assertSame([ 'review' => 'Combine customer and investor.' ], $result->output);
        $this->assertSame(expected: 'customer_story', actual: $result->history[ 0 ][ 'agent' ]);
        $this->assertSame([ 'customer_story', 'investor_story', 'review' ], $runner->agentNames());
    }

    public function test_it_executes_for_each_agents_with_iteration_context(): void
    {
        $runner = FakeAgentRunner::fake([
            'counter' => [ 1, 2, 3 ],
            'speller' => function (AgentInvocation $invocation): string {
                self::assertSame('test-model', $invocation->model);
                self::assertSame('test-key', $invocation->providerConfig[ 'api_key' ]);

                return [ 'one', 'two', 'three' ][ (int) $invocation->iterationValue - 1 ];
            },
        ]);

        $result = Workflow::fromFile(__DIR__ . '/Stubs/simple_loop.wire')
            ->withSecrets([
                'api_key' => 'test-key',
                'endpoint' => 'http://example.test/v1',
                'model' => 'test-model',
            ])
            ->run();

        $this->assertSame([ 'numbers' => [ 'one', 'two', 'three' ] ], $result->output);
        $this->assertSame('Please spell out the number: 1 in lowercase.', $result->history[ 2 ][ 'content' ]);
    }

    public function test_it_resolves_input_and_nested_output_interpolation(): void
    {
        FakeAgentRunner::fake([
            'release_summary' => fn (AgentInvocation $invocation): array => [
                'summary' => $invocation->prompt,
                'tagline' => 'Ship it',
            ],
            'launch_message' => fn (AgentInvocation $invocation): array => [
                'body' => $invocation->prompt,
            ],
        ]);

        $result = Workflow::fromFile(__DIR__ . '/Stubs/interpolation_chain.wire')
            ->withInputs([
                'product_name' => 'Superwire',
                'audience' => 'developers',
            ])
            ->run();

        $this->assertSame(
            expected: [
                'body' => 'Write a launch note for developers about Summarize Superwire for developers. with tagline Ship it.',
                'summary' => 'Summarize Superwire for developers.',
            ],
            actual: $result->output,
        );
    }

    public function test_artisan_command_compiles_wire_workflow_to_json(): void
    {
        $this->artisan('superwire:compile', [ 'workflow' => __DIR__ . '/Stubs/greeting.wire' ])
            ->expectsOutputToContain('"format": "superwire_workflow_compact_v1"')
            ->assertSuccessful();

        $definition = app(WorkflowCompiler::class)->compile(workflowPath: __DIR__ . '/Stubs/greeting.wire');

        $this->assertSame(
            expected: 'greeting',
            actual: $definition->agents->first()->name,
        );
    }

    public function test_it_accepts_tools_for_the_current_workflow_run(): void
    {
        FakeAgentRunner::fake([
            'assistant' => function (AgentInvocation $invocation): array {
                $this->assertCount(expectedCount: 2, haystack: $invocation->tools);

                return [ 'weather' => 'sunny' ];
            },
        ]);

        $result = Workflow::fromFile(__DIR__ . '/Stubs/tool_schema_retry.wire')
            ->withTools([ new BoundSchemaTool(), new RetryWeatherTool() ])
            ->run();

        $this->assertSame(
            expected: [ 'weather' => 'sunny' ],
            actual: $result->output,
        );
    }

    public function test_it_can_run_workflow_agents_using_stream_mode(): void
    {
        $runner = new FakeStreamableAgentRunner(
            responses: [ 'greeting' => 'request response' ],
            streamText: 'stream response',
        );

        app()->instance(AgentRunner::class, $runner);

        $result = Workflow::fromFile(__DIR__ . '/Stubs/greeting.wire')
            ->usingStreamMode()
            ->run();

        $this->assertSame(expected: [ 'greeting' => 'stream response' ], actual: $result->output);
        $this->assertSame(expected: 'greeting', actual: $runner->streamInvocation?->agent->name);
    }

    public function test_it_can_run_workflow_agents_using_request_mode(): void
    {
        config()->set('superwire.runtime.agent_mode', 'stream');

        $runner = new FakeStreamableAgentRunner(
            responses: [ 'greeting' => 'request response' ],
            streamText: 'stream response',
        );

        app()->instance(AgentRunner::class, $runner);

        $result = Workflow::fromFile(__DIR__ . '/Stubs/greeting.wire')
            ->usingRequestMode()
            ->run();

        $this->assertSame(expected: [ 'greeting' => 'request response' ], actual: $result->output);
        $this->assertNull(actual: $runner->streamInvocation);
    }

    public function test_it_can_run_workflow_using_parallel_executor(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('PCNTL is required for parallel workflow execution.');
        }

        FakeAgentRunner::fake([
            'customer_story' => 'customer',
            'investor_story' => 'investor',
            'review' => fn (AgentInvocation $invocation): string => $invocation->prompt,
        ]);

        $result = Workflow::fromFile(__DIR__ . '/Stubs/parallel_batch.wire')
            ->parallel()
            ->run();

        $this->assertSame(expected: [ 'review' => 'Combine customer and investor.' ], actual: $result->output);
    }

    public function test_it_can_select_parallel_executor_from_config(): void
    {
        config()->set('superwire.runtime.executor', 'parallel');

        $this->assertInstanceOf(expected: ParallelWorkflowExecutor::class, actual: app(\Superwire\Laravel\Contracts\WorkflowExecutor::class));
    }
}
