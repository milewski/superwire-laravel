<?php

declare(strict_types = 1);

namespace Superwire\Laravel;

use Illuminate\Support\ServiceProvider;
use Superwire\Laravel\Runtime\RemoteWorkflowExecutor;

final class SuperwireLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/superwire.php', 'superwire');

        $this->app->singleton(RemoteWorkflowExecutor::class, function (): RemoteWorkflowExecutor {

            return new RemoteWorkflowExecutor(
                baseUrl: (string) config('superwire.executor.url'),
                timeout: (int) config('superwire.executor.timeout', 300),
            );

        });
    }

    public function boot(): void
    {
        $this->publishes(
            paths: [ __DIR__ . '/../config/superwire.php' => config_path('superwire.php') ],
            groups: 'superwire-config',
        );
    }
}
