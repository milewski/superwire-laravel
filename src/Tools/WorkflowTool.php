<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tools;

use Laravel\Ai\Contracts\Tool;

interface WorkflowTool
{
    public static function name(): string;

    public function toAiTool(array $boundArguments = []): Tool;
}
