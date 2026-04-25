<?php

declare(strict_types = 1);

namespace Superwire\Laravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Superwire\Laravel\Console\CompileWorkflowCommand;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Contracts\WorkflowCompiler as WorkflowCompilerInterface;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Http\Controllers\InternalToolController;
use Superwire\Laravel\Runtime\Executor\SerialWorkflowExecutor;
use Superwire\Laravel\Runtime\Runner\LaravelAiAgentRunner;
use Superwire\Laravel\Runtime\Tool\ToolScopeRegistry;
use Superwire\Laravel\Runtime\Tool\ToolRegistry;
use Superwire\Laravel\Runtime\WorkflowCompiler;

final class SuperwireLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/superwire.php', 'superwire');

        $this->app->singleton(ToolRegistry::class);
        $this->app->singleton(ToolScopeRegistry::class);
        $this->app->bind(AgentRunner::class, LaravelAiAgentRunner::class);

        $this->app->bind(WorkflowCompilerInterface::class, function (): WorkflowCompilerInterface {
            return new WorkflowCompiler((string) config('superwire.cli.path'));
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

        Route::post('/_superwire/workflows/{workflow}/agents/{agent}/tools/{tool}', InternalToolController::class)
            ->name('superwire.tools.invoke');
    }
}
