<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Superwire\Laravel\Runtime\WorkflowCompiler;
use Superwire\Laravel\Tests\TestCase;

final class WorkflowCompilerTest extends TestCase
{
    public function test_it_compiles_a_workflow_file_to_json_using_laravel_process(): void
    {
        Process::fake(fn (): ProcessResult => Process::result(output: $this->compiledJson(workflowPath: '/tmp/workflow.wire')));

        $compiler = new WorkflowCompiler(binaryPath: '/usr/local/bin/superwire-cli');
        $workflowPath = $this->temporaryWorkflowFile();

        try {
            $json = $compiler->compileToJson(workflowPath: $workflowPath);
        } finally {
            unlink(filename: $workflowPath);
        }

        $this->assertStringContainsString(
            needle: 'superwire_workflow_compact_v1',
            haystack: $json,
        );

        Process::assertRan(function (PendingProcess $process) use ($workflowPath): bool {

            return $process->command === [
                '/usr/local/bin/superwire-cli',
                'workflow',
                'to-json',
                $workflowPath,
            ];

        });
    }

    public function test_it_parses_compiled_json_into_workflow_definition(): void
    {
        Process::fake(fn (): ProcessResult => Process::result(output: $this->compiledJson(workflowPath: '/tmp/workflow.wire')));

        $compiler = new WorkflowCompiler(binaryPath: '/usr/local/bin/superwire-cli');
        $workflowPath = $this->temporaryWorkflowFile();

        try {
            $definition = $compiler->compile(workflowPath: $workflowPath);
        } finally {
            unlink(filename: $workflowPath);
        }

        $this->assertSame(
            expected: 'superwire_workflow_compact_v1',
            actual: $definition->format,
        );
    }

    public function test_it_rejects_missing_workflow_files_without_running_process(): void
    {
        Process::fake();

        $compiler = new WorkflowCompiler(binaryPath: '/usr/local/bin/superwire-cli');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow file `/tmp/missing-workflow.wire` does not exist.');

        try {
            $compiler->compileToJson(workflowPath: '/tmp/missing-workflow.wire');
        } finally {
            Process::assertNothingRan();
        }
    }

    public function test_it_throws_process_error_output_when_cli_fails(): void
    {
        Process::fake(fn (): ProcessResult => Process::result(
            errorOutput: 'syntax error on line 4',
            exitCode: 1,
        ));

        $compiler = new WorkflowCompiler(binaryPath: '/usr/local/bin/superwire-cli');
        $workflowPath = $this->temporaryWorkflowFile();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('syntax error on line 4');

        try {
            $compiler->compileToJson(workflowPath: $workflowPath);
        } finally {
            unlink(filename: $workflowPath);
        }
    }

    public function test_it_throws_exit_code_when_cli_fails_without_error_output(): void
    {
        Process::fake(fn (): ProcessResult => Process::result(exitCode: 2));

        $compiler = new WorkflowCompiler(binaryPath: '/usr/local/bin/superwire-cli');
        $workflowPath = $this->temporaryWorkflowFile();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Superwire CLI exited with status 2.');

        try {
            $compiler->compileToJson(workflowPath: $workflowPath);
        } finally {
            unlink(filename: $workflowPath);
        }
    }

    public function test_it_rejects_empty_cli_output(): void
    {
        Process::fake(fn (): ProcessResult => Process::result(output: ''));

        $compiler = new WorkflowCompiler(binaryPath: '/usr/local/bin/superwire-cli');
        $workflowPath = $this->temporaryWorkflowFile();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Superwire CLI returned an empty JSON payload.');

        try {
            $compiler->compileToJson(workflowPath: $workflowPath);
        } finally {
            unlink(filename: $workflowPath);
        }
    }

    private function temporaryWorkflowFile(): string
    {
        $workflowPath = tempnam(directory: sys_get_temp_dir(), prefix: 'superwire-compiler-');

        if ($workflowPath === false) {
            throw new RuntimeException('Unable to create a temporary workflow file.');
        }

        file_put_contents(filename: $workflowPath, data: 'agent noop {}');

        return $workflowPath;
    }

    private function compiledJson(string $workflowPath): string
    {
        return json_encode(
            value: [
                'format' => 'superwire_workflow_compact_v1',
                'workflow_path' => $workflowPath,
                'input' => null,
                'secrets' => null,
                'schemas' => [],
                'tools' => [],
                'providers' => [],
                'agents' => [],
                'output' => [
                    'fields' => [],
                    'contract' => [
                        'workflow_type' => [
                            'kind' => 'object',
                            'fields' => [],
                        ],
                        'json_schema' => [
                            'type' => 'object',
                            'properties' => [],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'execution' => [
                    'order' => [],
                    'batches' => [],
                    'edges' => [],
                ],
            ],
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
