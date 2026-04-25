<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime\Executor;

use InvalidArgumentException;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\Executor\SerialWorkflowExecutor;
use Superwire\Laravel\Tests\Fixtures\FakeStreamableAgentRunner;
use Superwire\Laravel\Tests\Fixtures\Tools\BoundSchemaTool;
use Superwire\Laravel\Tests\Fixtures\Tools\RetryWeatherTool;
use Superwire\Laravel\Tests\Fixtures\FakeAgentRunner;
use Superwire\Laravel\Tests\TestCase;

final class SerialWorkflowExecutorTest extends TestCase
{
    public function test_it_executes_agents_in_batch_order_and_resolves_final_output(): void
    {
        $runner = FakeAgentRunner::fake([
            'customer_story' => 'customer',
            'investor_story' => 'investor',
            'review' => fn (AgentInvocation $invocation): string => $invocation->prompt,
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        $output = $executor->execute(
            definition: $this->workflowDefinition(fixture: 'parallel_batch.wire'),
        );

        $this->assertSame([ 'review' => 'Combine customer and investor.' ], $output);
        $this->assertSame([ 'customer_story', 'investor_story', 'review' ], $runner->agentNames());
        $this->assertSame([ 'customer_story' => 'customer', 'investor_story' => 'investor' ], $runner->invocation(2)->agentOutputs);
    }

    public function test_it_passes_resolved_inputs_secrets_model_prompt_and_provider_config_to_agent_runner(): void
    {
        $runner = FakeAgentRunner::fake([
            'counter' => [ 1, 2, 3 ],
            'speller' => fn (AgentInvocation $invocation): string => [ 'one', 'two', 'three' ][ (int) $invocation->iterationValue - 1 ],
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        $output = $executor->execute(
            definition: $this->workflowDefinition(fixture: 'simple_loop.wire'),
            secrets: [
                'api_key' => 'test-key',
                'endpoint' => 'http://example.test/v1',
                'model' => 'test-model',
            ],
        );

        $this->assertSame([ 'numbers' => [ 'one', 'two', 'three' ] ], $output);
        $this->assertSame('test-model', $runner->invocation(0)->model);
        $this->assertSame('http://example.test/v1', $runner->invocation(0)->providerConfig[ 'endpoint' ]);
        $this->assertSame('Please spell out the number: 1 in lowercase.', $runner->invocation(1)->prompt);
        $this->assertSame('number', $runner->invocation(1)->iterationIdentifier);
        $this->assertSame(1, $runner->invocation(1)->iterationValue);
    }

    public function test_it_uses_array_agent_output_for_schema_validation_and_reference_resolution(): void
    {
        $runner = FakeAgentRunner::fake([
            'release_summary' => fn (AgentInvocation $invocation): array => [
                'summary' => $invocation->prompt,
                'tagline' => 'Ship it',
            ],
            'launch_message' => fn (AgentInvocation $invocation): array => [
                'body' => $invocation->prompt,
            ],
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        $output = $executor->execute(
            definition: $this->workflowDefinition(fixture: 'interpolation_chain.wire'),
            inputs: [
                'product_name' => 'Superwire',
                'audience' => 'developers',
            ],
        );

        $this->assertSame(
            expected: [
                'body' => 'Write a launch note for developers about Summarize Superwire for developers. with tagline Ship it.',
                'summary' => 'Summarize Superwire for developers.',
            ],
            actual: $output,
        );
    }

    public function test_it_validates_required_inputs_before_running_agents(): void
    {
        $runner = FakeAgentRunner::fake([
            'release_summary' => [ 'summary' => 'unused', 'tagline' => 'unused' ],
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        try {

            $executor->execute(
                definition: $this->workflowDefinition(fixture: 'interpolation_chain.wire'),
                inputs: [
                    'product_name' => 'Superwire',
                ],
            );

            $this->fail(message: 'Expected input validation to fail.');

        } catch (InvalidArgumentException $exception) {

            $this->assertStringContainsString(
                needle: 'Invalid input',
                haystack: $exception->getMessage(),
            );

        }

        $this->assertSame([], $runner->agentNames());
    }

    public function test_it_rejects_agent_outputs_that_do_not_match_the_declared_schema(): void
    {
        $runner = FakeAgentRunner::fake([
            'greeting' => [ 'not' => 'a string' ],
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent `greeting` returned output that cannot be parsed as a string.');

        $executor->execute(
            definition: $this->workflowDefinition(fixture: 'greeting.wire'),
        );
    }

    public function test_it_retries_agent_outputs_that_fail_parsing(): void
    {
        config()->set('superwire.runtime.max_agent_request_attempts', 3);

        $listerCalls = 0;
        $runner = FakeAgentRunner::fake([
            'lister' => function () use (&$listerCalls): array {
                $listerCalls++;

                return $listerCalls === 1 ? [] : [ 'numbers' => [ 1, 2, 3, 4, 5 ] ];
            },
            'counter' => fn (AgentInvocation $invocation): string => match ((int) $invocation->iterationValue) {
                1 => 'one',
                2 => 'two',
                3 => 'three',
                4 => 'four',
                5 => 'five',
            },
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        $output = $executor->execute(
            definition: $this->workflowDefinition(fixture: 'example.wire'),
        );

        $this->assertSame(expected: [ 'numbers' => [ 'one', 'two', 'three', 'four', 'five' ] ], actual: $output);
        $this->assertSame(expected: [ 'lister', 'lister', 'counter', 'counter', 'counter', 'counter', 'counter' ], actual: $runner->agentNames());
        $this->assertStringContainsString(needle: 'Validation error: Agent `lister` returned output that cannot be parsed as an object.', haystack: $runner->invocation(1)->prompt);
        $this->assertStringContainsString(needle: 'Previous response: []', haystack: $runner->invocation(1)->prompt);
    }

    public function test_it_stops_retrying_agent_outputs_after_configured_attempts(): void
    {
        config()->set('superwire.runtime.max_agent_request_attempts', 2);

        $runner = FakeAgentRunner::fake([
            'lister' => [],
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent `lister` returned output that cannot be parsed as an object.');

        try {
            $executor->execute(
                definition: $this->workflowDefinition(fixture: 'example.wire'),
            );
        } finally {
            $this->assertSame(expected: [ 'lister', 'lister' ], actual: $runner->agentNames());
        }
    }

    public function test_it_rejects_execution_batches_that_run_before_their_dependencies(): void
    {
        $runner = FakeAgentRunner::fake([
            'customer_story' => 'customer',
            'investor_story' => 'investor',
            'review' => 'review',
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dependency `customer_story` has not been resolved');

        $executor->execute(
            definition: $this->workflowDefinitionWithBatches(
                fixture: 'parallel_batch.wire',
                batches: [ [ 'review' ], [ 'customer_story', 'investor_story' ] ],
            ),
        );
    }

    public function test_it_rejects_models_that_resolve_to_non_string_values(): void
    {
        $runner = FakeAgentRunner::fake([
            'greeting' => 'unused',
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent `greeting` model must resolve to a non-empty string.');

        $executor->execute(
            definition: $this->workflowDefinitionWithModelReference(fixture: 'greeting.wire'),
            inputs: [
                'model' => [ 'test-model' ],
            ],
        );
    }

    public function test_it_resolves_agent_tool_bindings_into_invocations(): void
    {
        $runner = FakeAgentRunner::fake([
            'assistant' => function (AgentInvocation $invocation): array {

                $this->assertCount(expectedCount: 2, haystack: $invocation->tools);

                $this->assertSame(
                    expected: 'bound_schema_tool',
                    actual: $invocation->tools[ 0 ]->definition->name,
                );

                $this->assertSame(
                    expected: [ 'tenant_id' => 'tenant-123' ],
                    actual: $invocation->tools[ 0 ]->bounded,
                );

                $this->assertSame(
                    expected: [],
                    actual: $invocation->tools[ 1 ]->bounded,
                );

                return [ 'weather' => 'sunny' ];

            },
        ]);

        $executor = new SerialWorkflowExecutor($runner);

        $this->assertSame(
            expected: [ 'weather' => 'sunny' ],
            actual: $executor->execute(
                definition: $this->workflowDefinition(fixture: 'tool_schema_retry.wire'),
                tools: [ new BoundSchemaTool(), new RetryWeatherTool() ],
            ),
        );
    }

    public function test_it_uses_configured_stream_agent_mode(): void
    {
        config()->set('superwire.runtime.agent_mode', 'stream');

        $runner = new FakeStreamableAgentRunner(
            responses: [ 'greeting' => 'request response' ],
            streamText: 'stream response',
        );

        $executor = new SerialWorkflowExecutor($runner);

        $output = $executor->execute(
            definition: $this->workflowDefinition(fixture: 'greeting.wire'),
        );

        $this->assertSame(expected: [ 'greeting' => 'stream response' ], actual: $output);
        $this->assertSame(expected: 'greeting', actual: $runner->streamInvocation?->agent->name);
        $this->assertSame(expected: [], actual: $runner->agentNames());
    }

    public function test_it_allows_explicit_request_agent_mode_to_override_configured_stream_mode(): void
    {
        config()->set('superwire.runtime.agent_mode', 'stream');

        $runner = new FakeStreamableAgentRunner(
            responses: [ 'greeting' => 'request response' ],
            streamText: 'stream response',
        );

        $executor = new SerialWorkflowExecutor($runner);

        $output = $executor->execute(
            definition: $this->workflowDefinition(fixture: 'greeting.wire'),
            agentMode: 'request',
        );

        $this->assertSame(expected: [ 'greeting' => 'request response' ], actual: $output);
        $this->assertNull(actual: $runner->streamInvocation);
    }

    public function test_it_rejects_stream_agent_mode_when_runner_does_not_support_streaming(): void
    {
        $runner = FakeAgentRunner::fake([ 'greeting' => 'request response' ]);
        $executor = new SerialWorkflowExecutor($runner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support streaming');

        $executor->execute(
            definition: $this->workflowDefinition(fixture: 'greeting.wire'),
            agentMode: 'stream',
        );
    }

    private function workflowDefinition(string $fixture): WorkflowDefinition
    {
        return app(WorkflowCompiler::class)->compile(
            workflowPath: __DIR__ . '/../../Stubs/' . $fixture,
        );
    }

    private function workflowDefinitionWithBatches(string $fixture, array $batches): WorkflowDefinition
    {
        $json = app(WorkflowCompiler::class)->compileToJson(
            workflowPath: __DIR__ . '/../../Stubs/' . $fixture,
        );

        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $payload[ 'execution' ][ 'batches' ] = $batches;

        return WorkflowDefinition::fromArray(payload: $payload);
    }

    private function workflowDefinitionWithModelReference(string $fixture): WorkflowDefinition
    {
        $json = app(WorkflowCompiler::class)->compileToJson(
            workflowPath: __DIR__ . '/../../Stubs/' . $fixture,
        );

        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $payload[ 'input' ] = [
            'workflow_type' => [
                'kind' => 'object',
                'fields' => [
                    'model' => [
                        'kind' => 'array',
                        'item_type' => [ 'kind' => 'string' ],
                        'fixed_length' => null,
                    ],
                ],
            ],
            'json_schema' => [
                'type' => 'object',
                'properties' => [
                    'model' => [
                        'type' => 'array',
                        'items' => [ 'type' => 'string' ],
                    ],
                ],
                'required' => [ 'model' ],
                'additionalProperties' => false,
            ],
        ];

        $payload[ 'agents' ][ 0 ][ 'model' ] = [ '$ref' => 'input.model' ];

        return WorkflowDefinition::fromArray(payload: $payload);
    }
}
