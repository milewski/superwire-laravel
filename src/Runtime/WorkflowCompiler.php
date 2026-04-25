<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Superwire\Laravel\Contracts\WorkflowCompiler as WorkflowCompilerInterface;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;

final readonly class WorkflowCompiler implements WorkflowCompilerInterface
{
    public function __construct(
        private string $binaryPath,
    )
    {
    }

    public function compile(string $workflowPath): WorkflowDefinition
    {
        return WorkflowDefinition::fromJson($this->compileToJson($workflowPath));
    }

    public function compileToJson(string $workflowPath): string
    {
        if (!is_file($workflowPath)) {
            throw new InvalidArgumentException(sprintf('Workflow file `%s` does not exist.', $workflowPath));
        }

        $command = [
            $this->binaryPath,
            'workflow',
            'to-json',
            $workflowPath,
        ];

        $result = Process::run($command);

        if ($result->failed()) {

            throw new RuntimeException(
                trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : sprintf('Superwire CLI exited with status %d.', $result->exitCode()),
            );

        }

        $output = $result->output();

        if (trim($output) === '') {
            throw new RuntimeException('Superwire CLI returned an empty JSON payload.');
        }

        return $output;
    }
}
