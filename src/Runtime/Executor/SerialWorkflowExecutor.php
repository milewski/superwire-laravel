<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Executor;

use InvalidArgumentException;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Contracts\StreamableAgentRunner;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Data\Agent\OutputField;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Enums\AgentMode;
use Superwire\Laravel\Enums\OutputStrategy;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\AgentRunResult;
use Superwire\Laravel\Runtime\OutputParser;
use Superwire\Laravel\Runtime\PromptRenderer;
use Superwire\Laravel\Runtime\ReferenceResolver;
use Superwire\Laravel\Runtime\Tool\BoundToolDefinition;
use Superwire\Laravel\Runtime\Tool\LaravelAiTool;
use Superwire\Laravel\Runtime\Tool\ToolScopeRegistry;
use Superwire\Laravel\Runtime\WorkflowResult;
use Superwire\Laravel\Support\JsonSchemaFactory;
use Superwire\Laravel\Tools\AbstractTool;
use TypeError;

readonly class SerialWorkflowExecutor implements WorkflowExecutor
{
    public function __construct(
        protected AgentRunner $agentRunner,
        protected PromptRenderer $promptRenderer = new PromptRenderer(),
        protected OutputParser $outputParser = new OutputParser(),
        protected ToolScopeRegistry $toolScopeRegistry = new ToolScopeRegistry(),
    )
    {
    }

    public function execute(WorkflowDefinition $definition, array $inputs = [], array $secrets = [], array $tools = [], ?string $runId = null, ?AgentMode $agentMode = null, ?OutputStrategy $outputStrategy = null): WorkflowResult
    {
        $definition->validateInputValues($inputs);
        $definition->validateSecretValues($secrets);
        $runId ??= hash('sha256', $definition->workflowPath . serialize($inputs) . serialize($secrets));
        $agentMode ??= $this->configuredAgentMode();
        $outputStrategy ??= $this->configuredOutputStrategy();
        $toolMap = $this->toolMap(tools: $tools);
        $dynamicValues = $this->resolveDynamicValues(
            expressions: $definition->dynamic,
            definition: $definition,
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: [],
            toolMap: $toolMap,
            runId: $runId,
            agentName: 'workflow',
        );

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
                        ? $this->runForEachAgent($definition, $agent, $inputs, $secrets, $agentOutputs, $dynamicValues, $toolMap, $runId, $agentMode, $outputStrategy)
                        : $this->runAgent($definition, $agent, $inputs, $secrets, $agentOutputs, $dynamicValues, $toolMap, $runId, $agentMode, outputStrategy: $outputStrategy);

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
                output: $this->resolveWorkflowOutput($definition, $inputs, $secrets, $agentOutputs, $dynamicValues),
                history: $history,
                context: [
                    'inputs' => $inputs,
                    'agent_outputs' => $agentOutputs,
                    'dynamic' => $dynamicValues,
                ],
            );

        } finally {

            $this->toolScopeRegistry->forget(runId: $runId);

        }
    }

    protected function runAgent(WorkflowDefinition $definition, Agent $agent, array $inputs, array $secrets, array $agentOutputs, array $dynamicValues, array $toolMap, string $runId, AgentMode $agentMode, OutputStrategy $outputStrategy, ?string $iterationIdentifier = null, mixed $iterationValue = null): array
    {
        $agentDynamicValues = $this->resolveDynamicValues(
            expressions: $agent->dynamic,
            definition: $definition,
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: $agentOutputs,
            toolMap: $toolMap,
            runId: $runId,
            agentName: $agent->name,
            baseDynamicValues: $dynamicValues,
            iterationIdentifier: $iterationIdentifier,
            iterationValue: $iterationValue,
        );
        $resolver = new ReferenceResolver(
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: $agentOutputs,
            dynamicValues: $agentDynamicValues,
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
            outputStrategy: $outputStrategy,
            iterationIdentifier: $iterationIdentifier,
            iterationValue: $iterationValue,
        );

        return $this->runInvocationWithRetries(
            invocation: $invocation,
            agentMode: $agentMode,
            field: $agent->runsForEach() ? $agent->output->iteration : $agent->output->finalOutput,
        );
    }

    protected function runInvocationWithRetries(AgentInvocation $invocation, AgentMode $agentMode, OutputField $field): array
    {
        $attempts = max(1, (int) config('superwire.runtime.max_agent_request_attempts', 10));
        $currentInvocation = $invocation;
        $history = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {

            try {

                $runResult = $this->runInvocation(invocation: $currentInvocation, agentMode: $agentMode);
                $history = [ ...$history, ...$this->attemptHistory(invocation: $invocation, attempt: $attempt, history: $runResult->history) ];
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

            } catch (InvalidArgumentException|TypeError $exception) {

                if ($attempt === $attempts) {
                    throw $exception;
                }

                $currentInvocation = $this->retryInvocation(
                    invocation: $invocation,
                    error: $exception,
                    previousOutput: $runResult->output ?? null,
                );

            }

        }

        throw new InvalidArgumentException(sprintf('Agent `%s` failed to produce a valid output.', $invocation->agent->name));
    }

    protected function retryInvocation(AgentInvocation $invocation, InvalidArgumentException|TypeError $error, string|array|null $previousOutput): AgentInvocation
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
            outputStrategy: $invocation->outputStrategy,
            iterationIdentifier: $invocation->iterationIdentifier,
            iterationValue: $invocation->iterationValue,
        );
    }

    protected function retryPrompt(string $prompt, InvalidArgumentException|TypeError $error, string|array|null $previousOutput): string
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

    protected function runForEachAgent(WorkflowDefinition $definition, Agent $agent, array $inputs, array $secrets, array $agentOutputs, array $dynamicValues, array $toolMap, string $runId, AgentMode $agentMode, OutputStrategy $outputStrategy): array
    {
        $resolver = new ReferenceResolver($inputs, $secrets, $agentOutputs, $dynamicValues);
        $iterable = $resolver->resolve($agent->forEachReference());

        if (!is_iterable($iterable)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` for_each reference must resolve to an iterable.', $agent->name));
        }

        $items = is_array($iterable) ? array_values($iterable) : iterator_to_array($iterable, false);

        return $this->runForEachAgentSerially(
            definition: $definition,
            agent: $agent,
            items: $items,
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: $agentOutputs,
            dynamicValues: $dynamicValues,
            toolMap: $toolMap,
            runId: $runId,
            agentMode: $agentMode,
            outputStrategy: $outputStrategy,
        );
    }

    protected function runForEachAgentSerially(WorkflowDefinition $definition, Agent $agent, array $items, array $inputs, array $secrets, array $agentOutputs, array $dynamicValues, array $toolMap, string $runId, AgentMode $agentMode, OutputStrategy $outputStrategy): array
    {
        $results = [];

        foreach ($items as $item) {

            $results[] = $this->runForEachAgentIteration(
                definition: $definition,
                agent: $agent,
                item: $item,
                inputs: $inputs,
                secrets: $secrets,
                agentOutputs: $agentOutputs,
                dynamicValues: $dynamicValues,
                toolMap: $toolMap,
                runId: $runId,
                agentMode: $agentMode,
                outputStrategy: $outputStrategy,
            );

        }

        return $this->collectForEachAgentResults(agent: $agent, results: $results);
    }

    protected function runForEachAgentIteration(WorkflowDefinition $definition, Agent $agent, mixed $item, array $inputs, array $secrets, array $agentOutputs, array $dynamicValues, array $toolMap, string $runId, AgentMode $agentMode, OutputStrategy $outputStrategy): array
    {
        return $this->runAgent(
            definition: $definition,
            agent: $agent,
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: $agentOutputs,
            dynamicValues: $dynamicValues,
            toolMap: $toolMap,
            runId: $runId,
            agentMode: $agentMode,
            outputStrategy: $outputStrategy,
            iterationIdentifier: $agent->forEachIdentifier(),
            iterationValue: $item,
        );
    }

    protected function collectForEachAgentResults(Agent $agent, array $results): array
    {
        $outputs = [];
        $history = [];

        foreach ($results as $result) {

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

    protected function assertDependenciesResolved(Agent $agent, array $agentOutputs): void
    {
        foreach ($agent->dependencies as $dependency) {

            if (!array_key_exists($dependency, $agentOutputs)) {
                throw new InvalidArgumentException(sprintf('Agent `%s` dependency `%s` has not been resolved.', $agent->name, $dependency));
            }

        }
    }

    protected function runInvocation(AgentInvocation $invocation, AgentMode $agentMode): AgentRunResult
    {
        return match ($agentMode) {
            AgentMode::Request => $this->agentRunner->run(invocation: $invocation),
            AgentMode::Stream => $this->runStreamInvocation(invocation: $invocation),
        };
    }

    protected function runStreamInvocation(AgentInvocation $invocation): AgentRunResult
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

    protected function attemptHistory(AgentInvocation $invocation, int $attempt, array $history): array
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

    protected function resolveDynamicValues(
        array $expressions,
        WorkflowDefinition $definition,
        array $inputs,
        array $secrets,
        array $agentOutputs,
        array $toolMap,
        string $runId,
        string $agentName,
        array $baseDynamicValues = [],
        ?string $iterationIdentifier = null,
        mixed $iterationValue = null,
    ): array
    {
        $dynamicValues = $baseDynamicValues;
        $pendingExpressions = $expressions;

        while ($pendingExpressions !== []) {

            $pendingCountBeforePass = count($pendingExpressions);
            $lastException = null;
            $resolver = new ReferenceResolver($inputs, $secrets, $agentOutputs, $dynamicValues, $iterationIdentifier, $iterationValue);

            foreach ($pendingExpressions as $fieldName => $expression) {

                try {

                    $dynamicValues[ $fieldName ] = $this->resolveValue(
                        value: $expression,
                        resolver: $resolver,
                        definition: $definition,
                        toolMap: $toolMap,
                        runId: $runId,
                        agentName: $agentName,
                    );
                    unset($pendingExpressions[ $fieldName ]);

                } catch (InvalidArgumentException $exception) {

                    $lastException = $exception;

                }

            }

            if (count($pendingExpressions) === $pendingCountBeforePass) {

                if ($lastException !== null) {
                    throw $lastException;
                }

                break;

            }

        }

        return $dynamicValues;
    }

    protected function resolveWorkflowOutput(WorkflowDefinition $definition, array $inputs, array $secrets, array $agentOutputs, array $dynamicValues): array
    {
        $resolver = new ReferenceResolver($inputs, $secrets, $agentOutputs, $dynamicValues);

        $output = array_map(
            callback: fn (mixed $expression): mixed => $this->resolveValue(value: $expression, resolver: $resolver),
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

    protected function resolveValue(mixed $value, ReferenceResolver $resolver, ?WorkflowDefinition $definition = null, array $toolMap = [], ?string $runId = null, ?string $agentName = null): mixed
    {
        if (is_array($value) && count($value) === 1 && isset($value[ '$ref' ]) && is_string($value[ '$ref' ])) {
            return $resolver->resolve($value[ '$ref' ]);
        }

        if (is_array($value) && isset($value[ '$tool_call' ]) && is_string($value[ '$tool_call' ])) {
            if ($definition === null || $runId === null || $agentName === null) {
                throw new InvalidArgumentException('Manual tool calls require workflow execution context.');
            }

            return $this->executeManualToolCall($value, $resolver, $definition, $toolMap, $runId, $agentName);
        }

        if (!is_array($value)) {
            return $value;
        }

        return array_map(
            callback: fn (mixed $child): mixed => is_string($child) || is_array($child)
                ? $this->resolveValue($child, $resolver, $definition, $toolMap, $runId, $agentName)
                : $child,
            array: $value,
        );
    }

    protected function executeManualToolCall(array $toolCall, ReferenceResolver $resolver, WorkflowDefinition $definition, array $toolMap, string $runId, string $agentName): array
    {
        $toolName = $this->manualToolCallName($toolCall[ '$tool_call' ]);
        $toolDefinition = $definition->toolDefinitionNamed($toolName);

        if ($toolDefinition === null) {
            throw new InvalidArgumentException(sprintf('Manual tool call references unknown tool `%s`.', $toolName));
        }

        if (!isset($toolMap[ $toolName ])) {
            throw new InvalidArgumentException(sprintf('Workflow tool `%s` was not provided.', $toolName));
        }

        $input = $this->resolveValue(
            value: is_array($toolCall[ 'input' ] ?? null) ? $toolCall[ 'input' ] : [],
            resolver: $resolver,
            definition: $definition,
            toolMap: $toolMap,
            runId: $runId,
            agentName: $agentName,
        );
        $bindings = $this->resolveValue(
            value: is_array($toolCall[ 'bindings' ] ?? null) ? $toolCall[ 'bindings' ] : [],
            resolver: $resolver,
            definition: $definition,
            toolMap: $toolMap,
            runId: $runId,
            agentName: $agentName,
        );

        if (!is_array($input) || !is_array($bindings)) {
            throw new InvalidArgumentException(sprintf('Manual tool call `%s` input and bindings must resolve to objects.', $toolName));
        }

        $binding = new BoundToolDefinition(
            definition: $toolDefinition,
            bounded: $bindings,
            runId: $runId,
            agentName: $agentName,
            toolClass: $toolMap[ $toolName ]::class,
            workflowPath: $definition->workflowPath,
        );

        $result = (new LaravelAiTool($binding))->handle(new Request($input));
        $decodedResult = json_decode($result, true);

        if (!is_array($decodedResult)) {
            throw new InvalidArgumentException(sprintf('Manual tool call `%s` returned invalid JSON.', $toolName));
        }

        if (array_key_exists('error', $decodedResult)) {
            throw new InvalidArgumentException(sprintf('Manual tool call `%s` failed: %s', $toolName, (string) $decodedResult[ 'error' ]));
        }

        return $decodedResult;
    }

    protected function manualToolCallName(string $toolReference): string
    {
        if (!str_starts_with($toolReference, 'tool.')) {
            throw new InvalidArgumentException(sprintf('Manual tool call must target `tool.<name>`, received `%s`.', $toolReference));
        }

        return substr($toolReference, strlen('tool.'));
    }

    protected function resolveModel(Agent $agent, ReferenceResolver $resolver): string
    {
        $model = $agent->model->isReference()
            ? $resolver->resolve(reference: $agent->model->reference)
            : $agent->model->name;

        if (!is_string($model) || $model === '') {
            throw new InvalidArgumentException(sprintf('Agent `%s` model must resolve to a non-empty string.', $agent->name));
        }

        return $model;
    }

    protected function resolveTools(WorkflowDefinition $definition, Agent $agent, ReferenceResolver $resolver, array $toolMap, string $runId): array
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
                toolClass: $toolMap[ $toolDefinition->name ]::class,
                workflowPath: $definition->workflowPath,
            );

            $this->toolScopeRegistry->register(tool: $toolMap[ $toolDefinition->name ], binding: $binding);

            $tools[] = $binding;

        }

        return $tools;
    }

    protected function toolMap(array $tools): array
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

    protected function configuredAgentMode(): AgentMode
    {
        return AgentMode::from((string) config('superwire.runtime.agent_mode', AgentMode::Request->value));
    }

    protected function configuredOutputStrategy(): OutputStrategy
    {
        return OutputStrategy::from((string) config('superwire.runtime.output_strategy', OutputStrategy::Structured->value));
    }
}
