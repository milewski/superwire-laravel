<?php

declare(strict_types = 1);

namespace Superwire\Laravel;

use Generator;
use InvalidArgumentException;
use Superwire\Laravel\Runtime\ExecutorEvent;
use Superwire\Laravel\Runtime\RemoteWorkflowExecutor;
use Superwire\Laravel\Runtime\WorkflowResult;

final class Workflow
{
    private function __construct(
        private readonly string $sourceBase64,
        private readonly string $filePath,
        private array $inputs = [],
        private array $secrets = [],
        private ?string $outputClass = null,
    )
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Workflow file `%s` does not exist.', $path));
        }

        return new self(
            sourceBase64: base64_encode((string) file_get_contents($path)),
            filePath: $path,
        );
    }

    public static function fromSource(string $source): self
    {
        return new self(
            sourceBase64: base64_encode($source),
            filePath: 'inline',
        );
    }

    public function inputs(array $inputs): self
    {
        $workflow = clone $this;
        $workflow->inputs = $inputs;

        return $workflow;
    }

    public function secrets(array $secrets): self
    {
        $workflow = clone $this;
        $workflow->secrets = $secrets;

        return $workflow;
    }

    public function mapInto(string $class): self
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Output class `%s` does not exist.', $class));
        }

        $workflow = clone $this;
        $workflow->outputClass = $class;

        return $workflow;
    }

    public function run(): WorkflowResult
    {
        $result = $this->executor()->execute(
            sourceBase64: $this->sourceBase64,
            input: $this->inputs,
            secrets: $this->secrets,
        );

        if ($this->outputClass === null) {
            return $result;
        }

        return new WorkflowResult(
            output: $this->mapOutput($result->output),
            history: $result->history,
            context: $result->context,
        );
    }

    /**
     * @return Generator<ExecutorEvent>
     */
    public function stream(): Generator
    {
        yield from $this->executor()->executeStream(
            sourceBase64: $this->sourceBase64,
            input: $this->inputs,
            secrets: $this->secrets,
        );
    }

    public function streamToResult(): WorkflowResult
    {
        return $this->executor()->executeStreamToResult(
            sourceBase64: $this->sourceBase64,
            input: $this->inputs,
            secrets: $this->secrets,
        );
    }

    public function filePath(): string
    {
        return $this->filePath;
    }

    public function sourceBase64(): string
    {
        return $this->sourceBase64;
    }

    private function mapOutput(mixed $output): mixed
    {
        $class = $this->outputClass;

        if (method_exists($class, 'from')) {
            return $class::from($output);
        }

        if (is_array($output)) {
            return new $class(...$output);
        }

        return new $class($output);
    }

    private function executor(): RemoteWorkflowExecutor
    {
        return app(RemoteWorkflowExecutor::class);
    }
}
