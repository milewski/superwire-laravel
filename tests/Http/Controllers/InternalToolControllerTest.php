<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Http\Controllers;

use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Runtime\Tool\BoundToolDefinition;
use Superwire\Laravel\Runtime\Tool\ToolScopeRegistry;
use Superwire\Laravel\Tests\Fixtures\Tools\SearchTool;
use Superwire\Laravel\Tests\TestCase;

final class InternalToolControllerTest extends TestCase
{
    public function test_it_invokes_registered_tools_behind_internal_token(): void
    {
        config()->set('superwire.tools.internal_token', 'internal-token');

        $this->registerScopedTool();

        $response = $this->postJson(
            uri: '/_superwire/workflows/run-1/agents/assistant/tools/search',
            data: [ 'input' => [ 'query' => 'laravel' ] ],
            headers: [ 'Authorization' => 'Bearer internal-token' ],
        );

        $response->assertOk();
        $response->assertJson([
            'result' => [
                'query' => 'laravel',
                'tenant' => 'tenant-123',
            ],
        ]);
    }

    public function test_it_rejects_requests_without_internal_token(): void
    {
        config()->set('superwire.tools.internal_token', 'internal-token');

        $response = $this->postJson(
            uri: '/_superwire/workflows/run-1/agents/assistant/tools/search',
            data: [
                'input' => [ 'query' => 'laravel' ],
            ],
        );

        $response->assertUnauthorized();
    }

    public function test_it_returns_schema_validation_errors_as_tool_errors(): void
    {
        config()->set('superwire.tools.internal_token', 'internal-token');

        $this->registerScopedTool();

        $response = $this->postJson(
            uri: '/_superwire/workflows/run-1/agents/assistant/tools/search',
            data: [
                'input' => [],
            ],
            headers: [ 'Authorization' => 'Bearer internal-token' ],
        );

        $response->assertStatus(422);
        $response->assertJsonFragment([ 'error' => 'Invalid tool `search` input: Object expected, [] received' ]);
    }

    private function registerScopedTool(): void
    {
        app(ToolScopeRegistry::class)->register(
            tool: new SearchTool(),
            binding: new BoundToolDefinition(
                definition: ToolDefinition::fromArray([ 'name' => 'search', ...$this->definitionPayload() ]),
                bounded: [ 'tenant_id' => 'tenant-123' ],
                runId: 'run-1',
                agentName: 'assistant',
            ),
        );
    }

    private function definitionPayload(): array
    {
        return [
            'description' => 'Search documents',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [ 'type' => 'string' ],
                ],
                'required' => [ 'query' ],
                'additionalProperties' => false,
            ],
            'bounded_schema' => [
                'type' => 'object',
                'properties' => [
                    'tenant_id' => [ 'type' => 'string' ],
                ],
                'required' => [ 'tenant_id' ],
                'additionalProperties' => false,
            ],
        ];
    }
}
