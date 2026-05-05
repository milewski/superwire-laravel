<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Superwire\Laravel\Workflow as BaseWorkflow;

/**
 * @method static BaseWorkflow fromFile(string $path)
 * @method static BaseWorkflow fromSource(string $source)
 */
class Workflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaseWorkflow::class;
    }
}
