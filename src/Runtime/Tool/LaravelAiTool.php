<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class LaravelAiTool implements Tool
{
    public function __construct(
        private BoundToolDefinition $tool,
        private ToolInvoker $invoker,
        private JsonSchemaTypeMapper $schemaTypeMapper = new JsonSchemaTypeMapper(),
    )
    {
    }

    public function description(): string
    {
        return $this->tool->definition->description ?? $this->tool->definition->name;
    }

    public function handle(Request $request): string
    {
        $result = $this->invoker->invoke(
            definition: $this->tool->definition,
            input: $request->all(),
            bounded: $this->tool->bounded,
        );

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->schemaTypeMapper->properties(
            schemaDefinition: $this->tool->definition->inputSchemaDefinition,
            schema: $schema,
        );
    }
}
