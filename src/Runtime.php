<?php

declare(strict_types = 1);

namespace Superwire\Laravel;

use Laravel\Ai\Tools\Request;
use RuntimeException;
use Spatie\LaravelData\Data;
use Superwire\Laravel\Concerns\ExecutesWorkflowAgents;
use Superwire\Laravel\Concerns\HandlesForkedWorkflowExecution;
use Superwire\Laravel\Concerns\ResolvesRuntimeProviders;
use Superwire\Laravel\Concerns\ResolvesWorkflowTools;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Support\PromptParser;
use Superwire\Laravel\Tools\AbstractTool;
use Superwire\Laravel\Tools\WorkflowTool;

final readonly class Runtime
{
    use ExecutesWorkflowAgents;
    use HandlesForkedWorkflowExecution;
    use ResolvesRuntimeProviders;
    use ResolvesWorkflowTools;

    public function __construct(
        private WorkflowDefinition $definition,
        private PromptParser $promptParser = new PromptParser(),
        private array $inputValues = [],
        private array $secretValues = [],
        private array $tools = [],
        private ?string $outputClass = null,
    )
    {
    }

    /**
     * @param array<string, mixed> $inputValues
     */
    public function withInputs(array $inputValues): self
    {
        return new self(
            definition: $this->definition,
            promptParser: $this->promptParser,
            inputValues: $inputValues,
            secretValues: $this->secretValues,
            tools: $this->tools,
            outputClass: $this->outputClass,
        );
    }

    /**
     * @param array<string, mixed> $secretValues
     */
    public function withSecrets(array $secretValues): self
    {
        return new self(
            definition: $this->definition,
            promptParser: $this->promptParser,
            inputValues: $this->inputValues,
            secretValues: $secretValues,
            tools: $this->tools,
            outputClass: $this->outputClass,
        );
    }

    /**
     * @param array<int, string|WorkflowTool> $tools
     */
    public function withTools(array $tools): self
    {
        return new self(
            definition: $this->definition,
            promptParser: $this->promptParser,
            inputValues: $this->inputValues,
            secretValues: $this->secretValues,
            tools: $tools,
            outputClass: $this->outputClass,
        );
    }

    /**
     * @param class-string|null $outputClass
     */
    public function mapInto(?string $outputClass): self
    {
        return new self(
            definition: $this->definition,
            promptParser: $this->promptParser,
            inputValues: $this->inputValues,
            secretValues: $this->secretValues,
            tools: $this->tools,
            outputClass: $outputClass,
        );
    }

    public function run(): WorkflowExecutionResult
    {
        $this->definition->validateInputValues($this->inputValues);
        $this->definition->validateSecretValues($this->secretValues);

        $agentOutputs = [];
        $dynamicValues = $this->resolveDynamicValues($this->definition->dynamic, $agentOutputs);

        foreach ($this->definition->execution->batches as $batchAgentNames) {

            $agentOutputs = array_merge(
                $agentOutputs,
                $this->runBatch($batchAgentNames, $agentOutputs, $dynamicValues),
            );

        }

        $workflowOutput = $this->resolveWorkflowOutput($agentOutputs, $dynamicValues);

        return new WorkflowExecutionResult(
            output: $this->mapOutput($workflowOutput),
            agents: $agentOutputs,
            context: [
                'agent_outputs' => array_map(
                    static fn (AgentExecutionResult $agentExecutionResult): mixed => $agentExecutionResult->output,
                    $agentOutputs,
                ),
                'inputs' => $this->inputValues,
                'secrets' => $this->secretValues,
                'dynamic' => $dynamicValues,
            ],
        );
    }

    /**
     * @param array<string, mixed> $workflowOutput
     */
    private function mapOutput(array $workflowOutput): mixed
    {
        if ($this->outputClass === null) {
            return $workflowOutput;
        }

        if (is_subclass_of($this->outputClass, Data::class)) {
            return $this->outputClass::from($workflowOutput);
        }

        if (method_exists($this->outputClass, 'from')) {
            return $this->outputClass::from($workflowOutput);
        }

        return new ($this->outputClass)(...$workflowOutput);
    }

    private function resolveWorkflowOutput(array $agentOutputs, array $dynamicValues): array
    {
        return array_map(
            callback: fn (mixed $expression): mixed => $this->resolveExpression($expression, $agentOutputs, [], $dynamicValues),
            array: $this->definition->output->fields,
        );
    }

    private function resolveDynamicValues(array $dynamicExpressions, array $agentOutputs, array $scope = [], array $baseDynamicValues = []): array
    {
        $resolvedDynamicValues = $baseDynamicValues;
        $pendingDynamicExpressions = $dynamicExpressions;

        while ($pendingDynamicExpressions !== []) {

            $pendingCountBeforePass = count($pendingDynamicExpressions);
            $lastException = null;

            foreach ($pendingDynamicExpressions as $fieldName => $expression) {

                try {

                    $resolvedDynamicValues[ $fieldName ] = $this->resolveExpression($expression, $agentOutputs, $scope, $resolvedDynamicValues);
                    unset($pendingDynamicExpressions[ $fieldName ]);

                } catch (RuntimeException $runtimeException) {

                    $lastException = $runtimeException;

                }

            }

            if (count($pendingDynamicExpressions) === $pendingCountBeforePass) {

                if ($lastException !== null) {
                    throw $lastException;
                }

                break;

            }

        }

        return $resolvedDynamicValues;
    }

    private function resolveExpression(mixed $expression, array $agentOutputs, array $scope, array $dynamicValues): mixed
    {
        if (!is_array($expression)) {
            return $expression;
        }

        if (array_keys($expression) === [ '$ref' ] && is_string($expression[ '$ref' ])) {
            return $this->promptParser->resolveReference(
                reference: $expression[ '$ref' ],
                agentOutputs: $agentOutputs,
                scope: $scope,
                inputValues: $this->inputValues,
                secretValues: $this->secretValues,
                dynamicValues: $dynamicValues,
            );
        }

        if (array_key_exists('$template', $expression) && is_array($expression[ '$template' ])) {
            return $this->resolveTemplateExpression($expression[ '$template' ], $agentOutputs, $scope, $dynamicValues);
        }

        if (array_key_exists('$tool_call', $expression) && is_string($expression[ '$tool_call' ])) {
            return $this->executeManualToolCall($expression, $agentOutputs, $scope, $dynamicValues);
        }

        if (array_keys($expression) === [ '$expr' ]) {
            return $this->resolveExpression($expression[ '$expr' ], $agentOutputs, $scope, $dynamicValues);
        }

        $resolvedValue = [];

        foreach ($expression as $key => $value) {
            $resolvedValue[ $key ] = $this->resolveExpression($value, $agentOutputs, $scope, $dynamicValues);
        }

        return $resolvedValue;
    }

    private function resolveTemplateExpression(array $templateParts, array $agentOutputs, array $scope, array $dynamicValues): string
    {
        $resolvedTemplate = '';

        foreach ($templateParts as $templatePart) {

            if (!is_array($templatePart)) {
                $resolvedTemplate .= (string) $templatePart;

                continue;
            }

            $resolvedValue = $this->resolveExpression($templatePart, $agentOutputs, $scope, $dynamicValues);

            $resolvedTemplate .= is_array($resolvedValue)
                ? json_encode($resolvedValue, JSON_THROW_ON_ERROR)
                : (string) $resolvedValue;

        }

        return $resolvedTemplate;
    }

    private function executeManualToolCall(array $expression, array $agentOutputs, array $scope, array $dynamicValues): mixed
    {
        $toolName = $this->manualToolCallName($expression[ '$tool_call' ]);
        $toolDefinition = $this->definition->toolDefinitionNamed($toolName);

        if ($toolDefinition === null) {
            throw new RuntimeException(sprintf('Compiled workflow is missing a tool definition for `%s`.', $toolName));
        }

        $tool = $this->configuredToolNamed($toolName);
        $input = $this->resolveExpression($expression[ 'input' ] ?? [], $agentOutputs, $scope, $dynamicValues);
        $bindings = $this->resolveExpression($expression[ 'bindings' ] ?? [], $agentOutputs, $scope, $dynamicValues);

        if (!is_array($input) || !is_array($bindings)) {
            throw new RuntimeException(sprintf('Manual tool call `%s` input and bindings must resolve to objects.', $toolName));
        }

        $toolDefinition->validateAgentArguments($input);
        $toolDefinition->validateBoundArguments($bindings);

        if ($tool instanceof AbstractTool) {
            return $tool->execute(
                agentInput: $tool::resolveAgentInput($input),
                boundInput: $tool::resolveBoundInput($bindings),
            );
        }

        $result = (string) $tool->toAiTool($bindings)->handle(new Request($input));
        $decodedResult = json_decode($result, true);

        return json_last_error() === JSON_ERROR_NONE ? $decodedResult : $result;
    }

    private function manualToolCallName(string $toolReference): string
    {
        $prefix = 'tool.';

        if (!str_starts_with($toolReference, $prefix)) {
            throw new RuntimeException(sprintf('Manual tool call must target `tool.<name>`, received `%s`.', $toolReference));
        }

        return substr($toolReference, strlen($prefix));
    }
}
