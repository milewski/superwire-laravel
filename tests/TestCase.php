<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RuntimeException;
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
        $app[ 'config' ]->set('superwire.cli.path', dirname(__DIR__, 3) . '/superwire-cli');
    }

    protected function writeTemporaryWorkflow(string $wire, string $prefix = 'superwire-workflow-'): string
    {
        $workflowPath = tempnam(directory: sys_get_temp_dir(), prefix: $prefix);

        if ($workflowPath === false) {
            throw new RuntimeException('Unable to create temporary workflow file.');
        }

        file_put_contents(filename: $workflowPath, data: $wire);

        return $workflowPath;
    }
}
