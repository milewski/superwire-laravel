<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime\Executor;

use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Enums\OutputStrategy;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\Executor\ParallelWorkflowExecutor;
use Superwire\Laravel\Tests\Fixtures\FakeAgentRunner;
use Superwire\Laravel\Tests\TestCase;

final class ParallelWorkflowExecutorTest extends TestCase
{
    public function test_it_executes_agents_in_the_same_batch_in_parallel(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('PCNTL is required for parallel workflow execution.');
        }

        $runner = FakeAgentRunner::fake([
            'customer_story' => function (): string {
                usleep(250_000);

                return 'customer';
            },
            'investor_story' => function (): string {
                usleep(250_000);

                return 'investor';
            },
            'review' => fn (AgentInvocation $invocation): string => $invocation->prompt,
        ]);

        $executor = new ParallelWorkflowExecutor($runner);

        $started = microtime(true);

        $result = $executor->execute(
            definition: $this->workflowDefinition(fixture: 'parallel_batch.wire'),
        );

        $elapsed = microtime(true) - $started;

        $this->assertSame(expected: [ 'review' => 'Combine customer and investor.' ], actual: $result->output);
        $this->assertLessThan(0.45, $elapsed);
    }

    public function test_it_limits_parallel_execution_from_config(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('PCNTL is required for parallel workflow execution.');
        }

        config()->set('superwire.runtime.max_parallel_agents', 1);

        $runner = FakeAgentRunner::fake([
            'customer_story' => function (): string {
                usleep(200_000);

                return 'customer';
            },
            'investor_story' => function (): string {
                usleep(200_000);

                return 'investor';
            },
            'review' => fn (AgentInvocation $invocation): string => $invocation->prompt,
        ]);

        $executor = new ParallelWorkflowExecutor($runner);

        $started = microtime(true);

        $executor->execute(definition: $this->workflowDefinition(fixture: 'parallel_batch.wire'));
        $elapsed = microtime(true) - $started;

        $this->assertGreaterThan(0.38, $elapsed);
    }

    public function test_it_passes_output_strategy_to_parallel_agent_invocations(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('PCNTL is required for parallel workflow execution.');
        }

        $runner = FakeAgentRunner::fake([
            'greeting' => fn (AgentInvocation $invocation): string => $invocation->outputStrategy->value,
        ]);

        $executor = new ParallelWorkflowExecutor($runner);

        $result = $executor->execute(
            definition: $this->workflowDefinition(fixture: 'greeting.wire'),
            outputStrategy: OutputStrategy::ToolCalling,
        );

        $this->assertSame(expected: [ 'greeting' => 'tool_calling' ], actual: $result->output);
    }

    private function workflowDefinition(string $fixture): WorkflowDefinition
    {
        return app(WorkflowCompiler::class)->compile(
            workflowPath: __DIR__ . '/../../Stubs/' . $fixture,
        );
    }
}
