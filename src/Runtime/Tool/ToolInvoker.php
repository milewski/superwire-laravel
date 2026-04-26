<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Tools\AbstractTool;

final readonly class ToolInvoker
{
    public function invoke(AbstractTool $tool, ToolDefinition $definition, array $input, array $bounded): array
    {
        $definition->validateAgentArguments(arguments: $this->validationArguments(arguments: $input, schemaDefinition: $definition->inputSchemaDefinition));
        $definition->validateBoundArguments(arguments: $this->validationArguments(arguments: $bounded, schemaDefinition: $definition->boundedSchemaDefinition));

        return $tool->execute(
            input: $input,
            bounded: $bounded,
        );
    }

    private function validationArguments(array $arguments, array $schemaDefinition): array | object
    {
        if ($arguments === [] && ($schemaDefinition[ 'type' ] ?? null) === 'object') {
            return (object) [];
        }

        return $arguments;
    }
}
