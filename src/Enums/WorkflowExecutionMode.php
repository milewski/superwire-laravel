<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Enums;

enum WorkflowExecutionMode: string
{
    case Serial = 'serial';
    case Parallel = 'parallel';
}
