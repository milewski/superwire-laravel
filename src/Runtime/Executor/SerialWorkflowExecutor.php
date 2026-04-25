<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Executor;

use InvalidArgumentException;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Contracts\StreamableAgentRunner;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Data\Agent\OutputField;
use Superwire\Laravel\Data\Agent\OutputFieldReference;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\AgentRunResult;
use Superwire\Laravel\Runtime\OutputParser;
use Superwire\Laravel\Runtime\PromptRenderer;
use Superwire\Laravel\Runtime\ReferenceResolver;
use Superwire\Laravel\Runtime\WorkflowResult;
use Superwire\Laravel\Runtime\Tool\BoundToolDefinition;
use Superwire\Laravel\Runtime\Tool\ToolScopeRegistry;
use Superwire\Laravel\Support\JsonSchemaFactory;
use Superwire\Laravel\Tools\AbstractTool;
use Laravel\Ai\Streaming\Events\TextDelta;

final readonly class SerialWorkflowExecutor implements WorkflowExecutor
{
    public function __construct(
        private AgentRunner $agentRunner,
        private PromptRenderer $promptRenderer = new PromptRenderer(),
        private OutputParser $outputParser = new OutputParser(),
        private ToolScopeRegistry $toolScopeRegistry = new ToolScopeRegistry(),
    )
    {
    }

    public function execute(WorkflowDefinition $definition, array $inputs = [], array $secrets = [], array $tools = [], ?string $runId = null, ?string $agentMode = null): WorkflowResult
    {
        $definition->validateInputValues($inputs);
        $definition->validateSecretValues($secrets);
        $runId ??= hash('sha256', $definition->workflowPath . serialize($inputs) . serialize($secrets));
        $agentMode ??= (string) config('superwire.runtime.agent_mode', 'request');
        $toolMap = $this->toolMap(tools: $tools);

        try {

            $agentOutputs = [];
            $history = [];

            foreach ($definition->execution->batches as $batch) {

                foreach ($batch as $agentName) {

                    $agent = $definition->agents->findByName($agentName);

                    $this->assertDependenciesResolved(
                        agent: $agent,
                        agentOutputs: $agentOutputs,
                    );

                    $result = $agent->runsForEach()
                        ? $this->runForEachAgent($definition, $agent, $inputs, $secrets, $agentOutputs, $toolMap, $runId, $agentMode)
                        : $this->runAgent($definition, $agent, $inputs, $secrets, $agentOutputs, $toolMap, $runId, $agentMode);

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

    private function runAgent(WorkflowDefinition $definition, Agent $agent, array $inputs, array $secrets, array $agentOutputs, array $toolMap, string $runId, string $agentMode, ?string $iterationIdentifier = null, mixed $iterationValue = null): array
    {
        $resolver = new ReferenceResolver(
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: $agentOutputs,
            iterationIdentifier: $iterationIdentifier,
            iterationValue: $iterationValue,
        );

        $provider = $definition->providers->findByName($agent->provider);

        $invocation = new AgentInvocation(
            agent: $agent,
            provider: $provider,
            model: $this->resolveModel(agent: $agent, resolver: $resolver),
            prompt: $this->promptRenderer->render($agent->prompt, $resolver),
            providerConfig: $this->resolveValue($provider->config, $resolver),
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: $agentOutputs,
            tools: $this->resolveTools(definition: $definition, agent: $agent, resolver: $resolver, toolMap: $toolMap, runId: $runId),
            iterationIdentifier: $iterationIdentifier,
            iterationValue: $iterationValue,
        );

        return $this->runInvocationWithRetries(
            invocation: $invocation,
            agentMode: $agentMode,
            field: $agent->runsForEach() ? $agent->output->iteration : $agent->output->finalOutput,
        );
    }

    private function runInvocationWithRetries(AgentInvocation $invocation, string $agentMode, OutputField $field): array
    {
        $attempts = max(1, (int) config('superwire.runtime.max_agent_request_attempts', 10));
        $currentInvocation = $invocation;
        $history = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {

            $runResult = $this->runInvocation(invocation: $currentInvocation, agentMode: $agentMode);
            $history = [ ...$history, ...$this->attemptHistory(invocation: $invocation, attempt: $attempt, history: $runResult->history) ];

            try {

                $parsed = $this->outputParser->parse(output: $runResult->output, field: $field, agent: $invocation->agent);

                JsonSchemaFactory::validate(
                    schema: $field->jsonSchema,
                    value: $parsed,
                    name: sprintf('agent `%s` output', $invocation->agent->name),
                );

                return [
                    'output' => $parsed,
                    'history' => $history,
                ];

            } catch (InvalidArgumentException $exception) {

                if ($attempt === $attempts) {
                    throw $exception;
                }

                $currentInvocation = $this->retryInvocation(
                    invocation: $invocation,
                    error: $exception,
                    previousOutput: $runResult->output,
                );

            }

        }

        throw new InvalidArgumentException(sprintf('Agent `%s` failed to produce a valid output.', $invocation->agent->name));
    }

    private function retryInvocation(AgentInvocation $invocation, InvalidArgumentException $error, string | array | null $previousOutput): AgentInvocation
    {
        return new AgentInvocation(
            agent: $invocation->agent,
            provider: $invocation->provider,
            model: $invocation->model,
            prompt: $this->retryPrompt(prompt: $invocation->prompt, error: $error, previousOutput: $previousOutput),
            providerConfig: $invocation->providerConfig,
            inputs: $invocation->inputs,
            secrets: $invocation->secrets,
            agentOutputs: $invocation->agentOutputs,
            tools: $invocation->tools,
            iterationIdentifier: $invocation->iterationIdentifier,
            iterationValue: $invocation->iterationValue,
        );
    }

    private function retryPrompt(string $prompt, InvalidArgumentException $error, string | array | null $previousOutput): string
    {
        $encodedOutput = is_array($previousOutput)
            ? json_encode($previousOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $previousOutput;

        return trim($prompt) . PHP_EOL . PHP_EOL . implode(PHP_EOL, [
            'The previous response did not match the declared Superwire output schema.',
            'Validation error: ' . $error->getMessage(),
            'Previous response: ' . ($encodedOutput ?? '[unavailable]'),
            'Try again. Return only the requested final answer in the exact declared output shape.',
        ]);
    }

    private function runForEachAgent(WorkflowDefinition $definition, Agent $agent, array $inputs, array $secrets, array $agentOutputs, array $toolMap, string $runId, string $agentMode): array
    {
        $resolver = new ReferenceResolver($inputs, $secrets, $agentOutputs);
        $iterable = $resolver->resolve($agent->forEachReference());

        if (!is_iterable($iterable)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` for_each reference must resolve to an iterable.', $agent->name));
        }

        $outputs = [];
        $history = [];

        foreach ($iterable as $item) {

            $result = $this->runAgent(
                definition: $definition,
                agent: $agent,
                inputs: $inputs,
                secrets: $secrets,
                agentOutputs: $agentOutputs,
                toolMap: $toolMap,
                runId: $runId,
                agentMode: $agentMode,
                iterationIdentifier: $agent->forEachIdentifier(),
                iterationValue: $item,
            );

            JsonSchemaFactory::validate(
                schema: $agent->output->iteration->jsonSchema,
                value: $result[ 'output' ],
                name: sprintf('agent `%s` iteration output', $agent->name),
            );

            $outputs[] = $result[ 'output' ];
            $history = [ ...$history, ...$result[ 'history' ] ];

        }

        return [
            'output' => $outputs,
            'history' => $history,
        ];
    }

    private function assertDependenciesResolved(Agent $agent, array $agentOutputs): void
    {
        foreach ($agent->dependencies as $dependency) {
            if (!array_key_exists($dependency, $agentOutputs)) {
                throw new InvalidArgumentException(sprintf('Agent `%s` dependency `%s` has not been resolved.', $agent->name, $dependency));
            }
        }
    }

    private function runInvocation(AgentInvocation $invocation, string $agentMode): AgentRunResult
    {
        return match ($agentMode) {
            'request' => $this->agentRunner->run(invocation: $invocation),
            'stream' => $this->runStreamInvocation(invocation: $invocation),
            default => throw new InvalidArgumentException(sprintf('Unsupported Superwire agent mode `%s`.', $agentMode)),
        };
    }

    private function runStreamInvocation(AgentInvocation $invocation): AgentRunResult
    {
        if (!$this->agentRunner instanceof StreamableAgentRunner) {
            throw new InvalidArgumentException(sprintf('Configured agent runner `%s` does not support streaming.', $this->agentRunner::class));
        }

        $stream = $this->agentRunner->runStream(invocation: $invocation);
        $events = iterator_to_array($stream);
        $text = TextDelta::combine($events);

        return new AgentRunResult(
            output: $text,
            history: [
                [
                    'role' => 'user',
                    'content' => $invocation->prompt,
                ],
                [
                    'role' => 'assistant',
                    'content' => $text,
                    'events' => array_map(
                        callback: fn (object $event): array => method_exists($event, 'toArray') ? $event->toArray() : [ 'type' => $event::class ],
                        array: $events,
                    ),
                ],
            ],
        );
    }

    private function attemptHistory(AgentInvocation $invocation, int $attempt, array $history): array
    {
        return array_map(
            callback: fn (array $entry): array => [
                'agent' => $invocation->agent->name,
                'attempt' => $attempt,
                ...$entry,
            ],
            array: $history,
        );
    }

    private function resolveWorkflowOutput(WorkflowDefinition $definition, array $inputs, array $secrets, array $agentOutputs): array
    {
        $resolver = new ReferenceResolver($inputs, $secrets, $agentOutputs);

        $output = array_map(
            callback: fn (OutputFieldReference $field) => $resolver->resolve($field->ref),
            array: $definition->output->fields,
        );

        $schemaDefinition = $definition->output->contract[ 'json_schema' ] ?? null;

        if (is_array($schemaDefinition)) {

            JsonSchemaFactory::validate(
                schema: JsonSchemaFactory::fromArray($schemaDefinition, 'workflow output'),
                value: $output,
                name: 'workflow output',
            );

        }

        return $output;
    }

    private function resolveValue(string | array $value, ReferenceResolver $resolver): mixed
    {
        if (is_array($value) && count($value) === 1 && isset($value[ '$ref' ]) && is_string($value[ '$ref' ])) {
            return $resolver->resolve($value[ '$ref' ]);
        }

        if (!is_array($value)) {
            return $value;
        }

        return array_map(
            callback: fn (string | array $child) => $this->resolveValue($child, $resolver),
            array: $value,
        );
    }

    private function resolveModel(Agent $agent, ReferenceResolver $resolver): string
    {
        $model = $agent->model->isReference()
            ? $resolver->resolve(reference: $agent->model->reference)
            : $agent->model->name;

        if (!is_string($model) || $model === '') {
            throw new InvalidArgumentException(sprintf('Agent `%s` model must resolve to a non-empty string.', $agent->name));
        }

        return $model;
    }

    private function resolveTools(WorkflowDefinition $definition, Agent $agent, ReferenceResolver $resolver, array $toolMap, string $runId): array
    {
        $tools = [];

        foreach ($agent->tools as $toolPayload) {

            if (!is_array($toolPayload) || !is_string($toolPayload[ 'name' ] ?? null)) {
                continue;
            }

            $toolDefinition = $definition->toolDefinitionNamed($toolPayload[ 'name' ]);

            if ($toolDefinition === null) {
                throw new InvalidArgumentException(sprintf('Agent `%s` references unknown tool `%s`.', $agent->name, $toolPayload[ 'name' ]));
            }

            if (!isset($toolMap[ $toolDefinition->name ])) {
                throw new InvalidArgumentException(sprintf('Workflow tool `%s` was not provided.', $toolDefinition->name));
            }

            $binding = new BoundToolDefinition(
                definition: $toolDefinition,
                bounded: $this->resolveValue(
                    value: is_array($toolPayload[ 'bind' ] ?? null) ? $toolPayload[ 'bind' ] : [],
                    resolver: $resolver,
                ),
                runId: $runId,
                agentName: $agent->name,
            );

            $this->toolScopeRegistry->register(tool: $toolMap[ $toolDefinition->name ], binding: $binding);

            $tools[] = $binding;

        }

        return $tools;
    }

    private function toolMap(array $tools): array
    {
        $toolMap = [];

        foreach ($tools as $tool) {

            if (!$tool instanceof AbstractTool) {
                throw new InvalidArgumentException('Workflow tools must extend ' . AbstractTool::class . '.');
            }

            $toolMap[ $tool->name() ] = $tool;

        }

        return $toolMap;
    }
}
