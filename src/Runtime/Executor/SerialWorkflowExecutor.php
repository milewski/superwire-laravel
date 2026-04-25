<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Executor;

use InvalidArgumentException;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Data\Agent\OutputFieldReference;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\OutputParser;
use Superwire\Laravel\Runtime\PromptRenderer;
use Superwire\Laravel\Runtime\ReferenceResolver;
use Superwire\Laravel\Runtime\Tool\BoundToolDefinition;
use Superwire\Laravel\Support\JsonSchemaFactory;

final readonly class SerialWorkflowExecutor implements WorkflowExecutor
{
    public function __construct(
        private AgentRunner $agentRunner,
        private PromptRenderer $promptRenderer = new PromptRenderer(),
        private OutputParser $outputParser = new OutputParser(),
    )
    {
    }

    public function execute(WorkflowDefinition $definition, array $inputs = [], array $secrets = []): array
    {
        $definition->validateInputValues($inputs);
        $definition->validateSecretValues($secrets);

        $agentOutputs = [];

        foreach ($definition->execution->batches as $batch) {

            foreach ($batch as $agentName) {

                $agent = $definition->agents->findByName($agentName);

                $this->assertDependenciesResolved(
                    agent: $agent,
                    agentOutputs: $agentOutputs,
                );

                $agentOutputs[ $agent->name ] = $agent->runsForEach()
                    ? $this->runForEachAgent($definition, $agent, $inputs, $secrets, $agentOutputs)
                    : $this->runAgent($definition, $agent, $inputs, $secrets, $agentOutputs);

                JsonSchemaFactory::validate(
                    schema: $agent->output->finalOutput->jsonSchema,
                    value: $agentOutputs[ $agent->name ],
                    name: sprintf('agent `%s` output', $agent->name),
                );

            }

        }

        return $this->resolveWorkflowOutput($definition, $inputs, $secrets, $agentOutputs);
    }

    private function runAgent(WorkflowDefinition $definition, Agent $agent, array $inputs, array $secrets, array $agentOutputs, ?string $iterationIdentifier = null, mixed $iterationValue = null): mixed
    {
        $resolver = new ReferenceResolver(
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: $agentOutputs,
            iterationIdentifier: $iterationIdentifier,
            iterationValue: $iterationValue,
        );

        $provider = $definition->providers->findByName($agent->provider);

        $output = $this->agentRunner->run(new AgentInvocation(
            agent: $agent,
            provider: $provider,
            model: $this->resolveModel(agent: $agent, resolver: $resolver),
            prompt: $this->promptRenderer->render($agent->prompt, $resolver),
            providerConfig: $this->resolveValue($provider->config, $resolver),
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: $agentOutputs,
            tools: $this->resolveTools(definition: $definition, agent: $agent, resolver: $resolver),
            iterationIdentifier: $iterationIdentifier,
            iterationValue: $iterationValue,
        ));

        return $this->outputParser->parse(
            output: $output,
            field: $agent->runsForEach() ? $agent->output->iteration : $agent->output->finalOutput,
            agent: $agent,
        );
    }

    private function runForEachAgent(WorkflowDefinition $definition, Agent $agent, array $inputs, array $secrets, array $agentOutputs): array
    {
        $resolver = new ReferenceResolver($inputs, $secrets, $agentOutputs);
        $iterable = $resolver->resolve($agent->forEachReference());

        if (!is_iterable($iterable)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` for_each reference must resolve to an iterable.', $agent->name));
        }

        $outputs = [];

        foreach ($iterable as $item) {

            $output = $this->runAgent(
                definition: $definition,
                agent: $agent,
                inputs: $inputs,
                secrets: $secrets,
                agentOutputs: $agentOutputs,
                iterationIdentifier: $agent->forEachIdentifier(),
                iterationValue: $item,
            );

            JsonSchemaFactory::validate(
                schema: $agent->output->iteration->jsonSchema,
                value: $output,
                name: sprintf('agent `%s` iteration output', $agent->name),
            );

            $outputs[] = $output;

        }

        return $outputs;
    }

    private function assertDependenciesResolved(Agent $agent, array $agentOutputs): void
    {
        foreach ($agent->dependencies as $dependency) {
            if (!array_key_exists($dependency, $agentOutputs)) {
                throw new InvalidArgumentException(sprintf('Agent `%s` dependency `%s` has not been resolved.', $agent->name, $dependency));
            }
        }
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

    private function resolveTools(WorkflowDefinition $definition, Agent $agent, ReferenceResolver $resolver): array
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

            $tools[] = new BoundToolDefinition(
                definition: $toolDefinition,
                bounded: $this->resolveValue(
                    value: is_array($toolPayload[ 'bind' ] ?? null) ? $toolPayload[ 'bind' ] : [],
                    resolver: $resolver,
                ),
            );

        }

        return $tools;
    }
}
