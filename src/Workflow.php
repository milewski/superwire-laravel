<?php

declare(strict_types = 1);

namespace Superwire\Laravel;

use InvalidArgumentException;
use Illuminate\Support\Str;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;
use Superwire\Laravel\Enums\AgentMode;
use Superwire\Laravel\Enums\OutputStrategy;
use Superwire\Laravel\Enums\WorkflowExecutionMode;
use Superwire\Laravel\Runtime\Executor\ParallelWorkflowExecutor;
use Superwire\Laravel\Runtime\Executor\SerialWorkflowExecutor;
use Superwire\Laravel\Runtime\WorkflowResult;

final class Workflow
{
    private function __construct(
        private readonly WorkflowDefinition $definition,
        private array $inputs = [],
        private array $secrets = [],
        private array $tools = [],
        private ?AgentMode $agentMode = null,
        private ?OutputStrategy $outputStrategy = null,
        private ?WorkflowExecutionMode $executionMode = null,
    )
    {
    }

    public static function fromDefinition(WorkflowDefinition $definition): self
    {
        return new self($definition);
    }

    public static function fromArray(array $payload): self
    {
        return new self(WorkflowDefinition::fromArray($payload));
    }

    public static function fromJson(string $json): self
    {
        return new self(WorkflowDefinition::fromJson($json));
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Workflow file `%s` does not exist.', $path));
        }

        if (str_ends_with($path, '.wire')) {
            return new self(app(WorkflowCompiler::class)->compile($path));
        }

        return self::fromJson((string) file_get_contents($path));
    }

    public function withInputs(array $inputs): self
    {
        $workflow = clone $this;
        $workflow->inputs = $inputs;

        return $workflow;
    }

    public function withSecrets(array $secrets): self
    {
        $workflow = clone $this;
        $workflow->secrets = $secrets;

        return $workflow;
    }

    public function withTools(array $tools): self
    {
        $workflow = clone $this;
        $workflow->tools = $tools;

        return $workflow;
    }

    public function usingRequestMode(): self
    {
        $workflow = clone $this;
        $workflow->agentMode = AgentMode::Request;

        return $workflow;
    }

    public function usingStreamMode(): self
    {
        $workflow = clone $this;
        $workflow->agentMode = AgentMode::Stream;

        return $workflow;
    }

    public function withStrategy(OutputStrategy $strategy): self
    {
        $workflow = clone $this;
        $workflow->outputStrategy = $strategy;

        return $workflow;
    }

    public function serial(): self
    {
        $workflow = clone $this;
        $workflow->executionMode = WorkflowExecutionMode::Serial;

        return $workflow;
    }

    public function parallel(): self
    {
        $workflow = clone $this;
        $workflow->executionMode = WorkflowExecutionMode::Parallel;

        return $workflow;
    }

    public function run(): WorkflowResult
    {
        return $this->executor()->execute(
            definition: $this->definition,
            inputs: $this->inputs,
            secrets: $this->secrets,
            tools: $this->tools,
            runId: (string) Str::uuid(),
            agentMode: $this->agentMode,
            outputStrategy: $this->outputStrategy,
        );
    }

    public function definition(): WorkflowDefinition
    {
        return $this->definition;
    }

    private function executor(): WorkflowExecutor
    {
        return match ($this->executionMode) {
            WorkflowExecutionMode::Serial => app(SerialWorkflowExecutor::class),
            WorkflowExecutionMode::Parallel => app(ParallelWorkflowExecutor::class),
            null => app(WorkflowExecutor::class),
        };
    }
}
