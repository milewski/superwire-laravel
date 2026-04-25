<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests;

use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Tests\Fixtures\FakeAgentRunner;
use Superwire\Laravel\Workflow;

final class WorkflowTest extends TestCase
{
    public function test_it_executes_batches_and_resolves_agent_references(): void
    {
        $runner = FakeAgentRunner::fake([
            'customer_story' => 'customer',
            'investor_story' => 'investor',
            'review' => fn (AgentInvocation $invocation): string => $invocation->prompt,
        ]);

        $output = Workflow::fromFile(__DIR__ . '/Stubs/parallel_batch.wire')->run();

        $this->assertSame([ 'review' => 'Combine customer and investor.' ], $output);
        $this->assertSame([ 'customer_story', 'investor_story', 'review' ], $runner->agentNames());
    }

    public function test_it_executes_for_each_agents_with_iteration_context(): void
    {
        $runner = FakeAgentRunner::fake([
            'counter' => [ 1, 2, 3 ],
            'speller' => fn (AgentInvocation $invocation): string => [ 'one', 'two', 'three' ][ (int) $invocation->iterationValue - 1 ],
        ]);

        $output = Workflow::fromFile(__DIR__ . '/Stubs/simple_loop.wire')
            ->withSecrets([
                'api_key' => 'test-key',
                'endpoint' => 'http://example.test/v1',
                'model' => 'test-model',
            ])
            ->run();

        $this->assertSame([ 'numbers' => [ 'one', 'two', 'three' ] ], $output);
        $this->assertSame('Please spell out the number: 1 in lowercase.', $runner->invocation(1)->prompt);
        $this->assertSame('test-model', $runner->invocation(1)->model);
        $this->assertSame('test-key', $runner->invocation(1)->providerConfig[ 'api_key' ]);
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

        $output = Workflow::fromFile(__DIR__ . '/Stubs/interpolation_chain.wire')
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
            actual: $output,
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
}
