<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Tools\AbstractTool;

final readonly class ToolInvoker
{
    public function invoke(AbstractTool $tool, ToolDefinition $definition, array $input, array $bounded): array
    {
        $definition->validateAgentArguments(arguments: $input);
        $definition->validateBoundArguments(arguments: $bounded);

        return $tool->execute(
            input: $input,
            bounded: $bounded,
        );
    }
}
