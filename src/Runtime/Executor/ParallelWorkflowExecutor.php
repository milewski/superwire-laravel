<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Executor;

use Spatie\Fork\Fork;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Enums\AgentMode;
use Superwire\Laravel\Enums\OutputStrategy;
use Superwire\Laravel\Runtime\WorkflowResult;
use Superwire\Laravel\Support\JsonSchemaFactory;

readonly class ParallelWorkflowExecutor extends SerialWorkflowExecutor
{
    public function execute(WorkflowDefinition $definition, array $inputs = [], array $secrets = [], array $tools = [], ?string $runId = null, ?AgentMode $agentMode = null, ?OutputStrategy $outputStrategy = null): WorkflowResult
    {
        $definition->validateInputValues($inputs);
        $definition->validateSecretValues($secrets);
        $runId ??= hash('sha256', $definition->workflowPath . serialize($inputs) . serialize($secrets));
        $agentMode ??= $this->configuredAgentMode();
        $outputStrategy ??= $this->configuredOutputStrategy();
        $toolMap = $this->toolMap(tools: $tools);

        try {
            $agentOutputs = [];
            $history = [];

            foreach ($definition->execution->batches as $batch) {
                $tasks = [];

                foreach ($batch as $agentName) {
                    $agent = $definition->agents->findByName($agentName);
                    $this->assertDependenciesResolved(agent: $agent, agentOutputs: $agentOutputs);

                    $tasks[] = fn (): array => [
                        'agent' => $agent->name,
                        'result' => $agent->runsForEach()
                            ? $this->runForEachAgent($definition, $agent, $inputs, $secrets, $agentOutputs, $toolMap, $runId, $agentMode, $outputStrategy)
                            : $this->runAgent($definition, $agent, $inputs, $secrets, $agentOutputs, $toolMap, $runId, $agentMode, $outputStrategy),
                    ];
                }

                $results = Fork::new()
                    ->concurrent(max(1, (int) config('superwire.runtime.max_parallel_agents', 4)))
                    ->run(...$tasks);

                foreach ($results as $payload) {
                    $agent = $definition->agents->findByName($payload[ 'agent' ]);
                    $result = $payload[ 'result' ];
                    $agentOutputs[ $agent->name ] = $result[ 'output' ];
                    $history = [ ...$history, ...$result[ 'history' ] ];

                    JsonSchemaFactory::validate(
                        schema: $agent->output->finalOutput->jsonSchema,
                        value: $agentOutputs[ $agent->name ],
                        name: sprintf('agent `%s` output', $agent->name),
                    );
                }
            }

            return new WorkflowResult(
                output: $this->resolveWorkflowOutput($definition, $inputs, $secrets, $agentOutputs),
                history: $history,
            );
        } finally {
            $this->toolScopeRegistry->forget(runId: $runId);
        }
    }
}
