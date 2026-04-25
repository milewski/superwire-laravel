<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class LaravelAiTool implements Tool
{
    public function __construct(
        private BoundToolDefinition $tool,
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
        $response = Http::withToken((string) config('superwire.tools.internal_token'))->post(
            url: route('superwire.tools.invoke', [
                'workflow' => $this->tool->runId,
                'agent' => $this->tool->agentName,
                'tool' => $this->tool->definition->name,
            ]),
            data: [ 'input' => $request->all() ],
        );

        if ($response->failed()) {
            return json_encode([ 'error' => $response->json('error') ?? 'Tool invocation failed.' ], JSON_THROW_ON_ERROR);
        }

        return json_encode($response->json('result') ?? [], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->schemaTypeMapper->properties(
            schemaDefinition: $this->tool->definition->inputSchemaDefinition,
            schema: $schema,
        );
    }
}
