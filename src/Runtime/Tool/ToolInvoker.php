<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use Superwire\Laravel\Data\Workflow\ToolDefinition;

final readonly class ToolInvoker
{
    public function __construct(
        private ToolRegistry $registry,
    )
    {
    }

    public function invoke(ToolDefinition $definition, array $input, array $bounded): array
    {
        $definition->validateAgentArguments(arguments: $input);
        $definition->validateBoundArguments(arguments: $bounded);

        return $this->registry->get(name: $definition->name)->execute(
            input: $input,
            bounded: $bounded,
        );
    }
}
