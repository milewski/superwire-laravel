<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use InvalidArgumentException;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Support\JsonSchemaFactory;

final readonly class SerialWorkflowExecutor implements WorkflowExecutor
{
    public function __construct(
        private AgentRunner $agentRunner,
        private PromptRenderer $promptRenderer = new PromptRenderer(),
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
            model: $agent->model->isReference() ? $resolver->resolve($agent->model->reference) : $agent->model->name,
            prompt: $this->promptRenderer->render($agent->prompt, $resolver),
            providerConfig: $this->resolveValue($provider->config, $resolver),
            inputs: $inputs,
            secrets: $secrets,
            agentOutputs: $agentOutputs,
            iterationIdentifier: $iterationIdentifier,
            iterationValue: $iterationValue,
        ));

        return $this->normalize($output);
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $secrets
     * @param array<string, mixed> $agentOutputs
     */
    private function runForEachAgent(WorkflowDefinition $definition, Agent $agent, array $inputs, array $secrets, array $agentOutputs): array
    {
        $resolver = new ReferenceResolver($inputs, $secrets, $agentOutputs);
        $iterable = $resolver->resolve($agent->forEachReference());

        if (!is_iterable($iterable)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` for_each reference must resolve to an iterable.', $agent->name));
        }

        $outputs = [];

        foreach ($iterable as $item) {
            $output = $this->runAgent($definition, $agent, $inputs, $secrets, $agentOutputs, $agent->forEachIdentifier(), $item);
            JsonSchemaFactory::validate($agent->output->iteration->jsonSchema, $output, sprintf('agent `%s` iteration output', $agent->name));
            $outputs[] = $output;
        }

        return $outputs;
    }

    /**
     * @param array<string, mixed> $agentOutputs
     */
    private function assertDependenciesResolved(Agent $agent, array $agentOutputs): void
    {
        foreach ($agent->dependencies as $dependency) {
            if (!array_key_exists($dependency, $agentOutputs)) {
                throw new InvalidArgumentException(sprintf('Agent `%s` dependency `%s` has not been resolved.', $agent->name, $dependency));
            }
        }
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $secrets
     * @param array<string, mixed> $agentOutputs
     * @return array<string, mixed>
     */
    private function resolveWorkflowOutput(WorkflowDefinition $definition, array $inputs, array $secrets, array $agentOutputs): array
    {
        $resolver = new ReferenceResolver($inputs, $secrets, $agentOutputs);
        $output = [];

        foreach ($definition->output->fields as $name => $field) {
            $output[ $name ] = $resolver->resolve($field->ref);
        }

        $schemaDefinition = $definition->output->contract[ 'json_schema' ] ?? null;

        if (is_array($schemaDefinition)) {
            JsonSchemaFactory::validate(JsonSchemaFactory::fromArray($schemaDefinition, 'workflow output'), $output, 'workflow output');
        }

        return $output;
    }

    private function resolveValue(mixed $value, ReferenceResolver $resolver): mixed
    {
        if (is_array($value) && count($value) === 1 && isset($value[ '$ref' ]) && is_string($value[ '$ref' ])) {
            return $resolver->resolve($value[ '$ref' ]);
        }

        if (!is_array($value)) {
            return $value;
        }

        $resolved = [];

        foreach ($value as $key => $child) {
            $resolved[ $key ] = $this->resolveValue($child, $resolver);
        }

        return $resolved;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        }

        return $value;
    }
}
