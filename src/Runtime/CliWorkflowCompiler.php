<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use InvalidArgumentException;
use RuntimeException;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Data\Workflow\WorkflowDefinition;

final readonly class CliWorkflowCompiler implements WorkflowCompiler
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

        $descriptorSpec = [
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the Superwire CLI process.');
        }

        $output = stream_get_contents($pipes[ 1 ]);
        $errors = stream_get_contents($pipes[ 2 ]);
        fclose($pipes[ 1 ]);
        fclose($pipes[ 2 ]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim($errors) !== '' ? trim($errors) : sprintf('Superwire CLI exited with status %d.', $exitCode));
        }

        if (!is_string($output) || trim($output) === '') {
            throw new RuntimeException('Superwire CLI returned an empty JSON payload.');
        }

        return $output;
    }
}
