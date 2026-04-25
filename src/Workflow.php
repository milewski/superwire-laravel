<?php

declare(strict_types = 1);

namespace Superwire\Laravel;

use InvalidArgumentException;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;

final class Workflow
{
    private function __construct(
        private readonly WorkflowDefinition $definition,
        private array $inputs = [],
        private array $secrets = [],
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

    public function run(): array
    {
        return app(WorkflowExecutor::class)->execute(
            definition: $this->definition,
            inputs: $this->inputs,
            secrets: $this->secrets,
        );
    }

    public function definition(): WorkflowDefinition
    {
        return $this->definition;
    }
}
