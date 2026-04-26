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
        $response = Http::withToken((string) config('superwire.tools.internal_token'))
            ->acceptJson()
            ->post(
                url: $this->internalToolUrl(),
                data: [
                    'input' => $request->all(),
                    'workflow_path' => $this->tool->workflowPath,
                    'tool_class' => $this->tool->toolClass,
                    'bounded' => $this->tool->bounded,
                ],
            );

        if ($response->failed()) {
            return json_encode([ 'error' => $response->json('error') ?? 'Tool invocation failed.' ], JSON_THROW_ON_ERROR);
        }

        return json_encode($response->json('result') ?? [], JSON_THROW_ON_ERROR);
    }

    private function internalToolUrl(): string
    {
        $baseUrl = config('superwire.tools.internal_base_url');

        if (is_string($baseUrl) && $baseUrl !== '') {
            return rtrim($baseUrl, '/') . $this->internalToolPath();
        }

        return route('superwire.tools.invoke', [
            'agent' => $this->tool->agentName,
            'tool' => $this->tool->definition->name,
        ]);
    }

    private function internalToolPath(): string
    {
        return '/_superwire/a/' . rawurlencode($this->tool->agentName)
            . '/t/' . rawurlencode($this->tool->definition->name);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->schemaTypeMapper->properties(
            schemaDefinition: $this->tool->definition->inputSchemaDefinition,
            schema: $schema,
        );
    }
}
