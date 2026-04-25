<?php

declare(strict_types = 1);

namespace Superwire\Laravel;

use Illuminate\Support\ServiceProvider;
use Superwire\Laravel\Console\CompileWorkflowCommand;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Runtime\CliWorkflowCompiler;
use Superwire\Laravel\Runtime\Executor\SerialWorkflowExecutor;
use Superwire\Laravel\Runtime\MissingAgentRunner;

final class SuperwireLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/superwire.php', 'superwire');

        $this->app->bind(AgentRunner::class, MissingAgentRunner::class);

        $this->app->bind(WorkflowCompiler::class, function (): WorkflowCompiler {
            return new CliWorkflowCompiler((string) config('superwire.cli.path'));
        });

        $this->app->bind(WorkflowExecutor::class, SerialWorkflowExecutor::class);
    }

    public function boot(): void
    {
        $this->publishes([ __DIR__ . '/../config/superwire.php' => config_path('superwire.php') ], 'superwire-config');

        if ($this->app->runningInConsole()) {

            $this->commands([
                CompileWorkflowCommand::class,
            ]);

        }
    }
}
