<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Executor\Concerns;

use Spatie\Fork\Fork;
use Throwable;

trait ResetsDatabaseConnectionsForForks
{
    protected function fork(): Fork
    {
        return Fork::new()
            ->before(child: fn (): null => $this->purgeDatabaseConnections());
    }

    protected function purgeDatabaseConnections(): null
    {
        $database = $this->databaseManager();

        if ($database !== null && method_exists($database, 'purge')) {

            foreach ($this->databaseConnectionNames($database) as $name) {

                try {

                    $database->purge($name);

                } catch (Throwable) {

                    // Database cleanup must not mask the forked task's real failure.

                }

            }

        }

        return null;
    }

    protected function databaseConnectionNames(object $database): array
    {
        if (!method_exists($database, 'getConnections')) {
            return [ null ];
        }

        try {

            $names = array_keys($database->getConnections());

        } catch (Throwable) {

            return [ null ];

        }

        return $names === [] ? [ null ] : $names;
    }

    protected function databaseManager(): ?object
    {
        if (!function_exists('app')) {
            return null;
        }

        try {

            $app = app();

            if (method_exists($app, 'bound') && !$app->bound('db')) {
                return null;
            }

            return app('db');

        } catch (Throwable) {

            return null;

        }
    }
}
