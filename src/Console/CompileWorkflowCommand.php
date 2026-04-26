<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Console;

use Illuminate\Console\Command;
use Superwire\Laravel\Contracts\WorkflowCompiler;

final class CompileWorkflowCommand extends Command
{
    protected $signature = 'superwire:compile {workflow : Path to the .wire workflow} {--output= : Optional path to write the compiled JSON}';

    protected $description = 'Compile a Superwire .wire workflow into compact JSON.';

    public function handle(WorkflowCompiler $compiler): int
    {
        $json = $compiler->compileToJson((string) $this->argument('workflow'));
        $outputPath = $this->option('output');

        if (is_string($outputPath) && $outputPath !== '') {

            file_put_contents($outputPath, $json);
            $this->components->info(sprintf('Compiled workflow written to %s.', $outputPath));

            return self::SUCCESS;

        }

        $this->line($json);

        return self::SUCCESS;
    }
}
