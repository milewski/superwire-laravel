<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Testing\Fakes;

use Closure;
use Generator;
use PHPUnit\Framework\Assert;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Data\Events\WorkflowCompletedEvent;
use Superwire\Laravel\Data\Events\WorkflowStartedEvent;
use Superwire\Laravel\Enums\ExecutorEventKind;
use Superwire\Laravel\Runtime\ExecutorEvent;
use Superwire\Laravel\Runtime\WorkflowFormatResult;
use Superwire\Laravel\Runtime\WorkflowResult;
use Superwire\Laravel\Runtime\WorkflowValidationResult;

class WorkflowFake implements WorkflowExecutor
{
    /**
     * @var array<string, Closure>
     */
    private array $fakes = [];

    private ?Closure $fakeCallback = null;

    /**
     * @var array<int, array{source: string, input: array, secrets: array}>
     */
    private array $recorded = [];

    /**
     * @param array<string, mixed>|Closure $output
     */
    public function stub(mixed $output): self
    {
        $this->fakeCallback = $output instanceof Closure
            ? $output
            : fn (): array => $output;

        return $this;
    }

    /**
     * @param array<string, mixed>|Closure $output
     */
    public function forFile(string $path, mixed $output): self
    {
        $this->fakes[ $path ] = $output instanceof Closure
            ? $output
            : fn (): array => $output;

        return $this;
    }

    public function execute(string $sourceBase64, array $input = [], array $secrets = []): WorkflowResult
    {
        $this->record($sourceBase64, $input, $secrets);

        return new WorkflowResult(
            output: $this->resolveOutput($sourceBase64, $input, $secrets),
            history: [],
            context: [
                'input' => $input,
                'secrets' => $secrets,
            ],
        );
    }

    public function executeStream(string $sourceBase64, array $input = [], array $secrets = []): Generator
    {
        $this->record($sourceBase64, $input, $secrets);

        $output = $this->resolveOutput($sourceBase64, $input, $secrets);

        yield new ExecutorEvent(
            kind: ExecutorEventKind::WorkflowStarted,
            agentName: null,
            event: new WorkflowStartedEvent(),
        );

        yield new ExecutorEvent(
            kind: ExecutorEventKind::WorkflowCompleted,
            agentName: null,
            event: new WorkflowCompletedEvent(output: $output),
        );
    }

    public function executeStreamToResult(string $sourceBase64, array $input = [], array $secrets = []): WorkflowResult
    {
        $this->record($sourceBase64, $input, $secrets);

        return new WorkflowResult(
            output: $this->resolveOutput($sourceBase64, $input, $secrets),
            history: [],
            context: [
                'input' => $input,
                'secrets' => $secrets,
            ],
        );
    }

    public function validate(string $sourceBase64, array $secrets = []): WorkflowValidationResult
    {
        $this->record($sourceBase64, [], $secrets);

        return new WorkflowValidationResult(
            context: [
                'secrets' => $secrets,
            ],
        );
    }

    public function format(string $sourceBase64): WorkflowFormatResult
    {
        $this->record($sourceBase64, [], []);
        $decodedSource = base64_decode($sourceBase64, true);

        return new WorkflowFormatResult(formattedSource: $decodedSource === false ? '' : $decodedSource);
    }

    public function assertExecuted(int $count = -1): self
    {
        if ($count === -1) {

            Assert::assertNotEmpty($this->recorded, 'No workflow was executed.');

        } else {

            Assert::assertCount($count, $this->recorded, sprintf(
                'Expected %d workflow(s) to be executed, but %d were executed.',
                $count,
                count($this->recorded),
            ));

        }

        return $this;
    }

    public function assertNothingExecuted(): self
    {
        return $this->assertExecuted(0);
    }

    public function assertExecutedWith(array $input): self
    {
        Assert::assertNotEmpty($this->recorded, 'No workflow was executed.');

        $found = false;

        foreach ($this->recorded as $record) {

            if ($record[ 'input' ] === $input) {

                $found = true;
                break;

            }

        }

        Assert::assertTrue($found, sprintf(
            'No workflow was executed with input: %s',
            json_encode($input, JSON_THROW_ON_ERROR),
        ));

        return $this;
    }

    /**
     * @return array<int, array{source: string, input: array, secrets: array}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public function executionCount(): int
    {
        return count($this->recorded);
    }

    private function record(string $sourceBase64, array $input, array $secrets): void
    {
        $this->recorded[] = [
            'source' => $sourceBase64,
            'input' => $input,
            'secrets' => $secrets,
        ];
    }

    private function resolveOutput(string $sourceBase64, array $input, array $secrets): mixed
    {
        foreach ($this->fakes as $path => $callback) {

            if (base64_encode((string) file_get_contents($path)) === $sourceBase64) {
                return $callback($input, $secrets);
            }

        }

        if ($this->fakeCallback !== null) {
            return ($this->fakeCallback)($input, $secrets);
        }

        return null;
    }
}
