<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Superwire\Laravel\SuperwireLaravelServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            SuperwireLaravelServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('superwire.cli.path', dirname(__DIR__, 3).'/superwire-cli');
    }
}
