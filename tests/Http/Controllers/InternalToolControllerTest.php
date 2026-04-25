<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Http\Controllers;

use Superwire\Laravel\Runtime\Tool\ToolRegistry;
use Superwire\Laravel\Tests\Fixtures\Tools\SearchTool;
use Superwire\Laravel\Tests\TestCase;

final class InternalToolControllerTest extends TestCase
{
    public function test_it_invokes_registered_tools_behind_internal_token(): void
    {
        config()->set('superwire.tools.internal_token', 'internal-token');

        app(ToolRegistry::class)->register(tool: new SearchTool(), name: 'search');

        $response = $this->postJson(
            uri: '/_superwire/tools/search',
            data: [
                'definition' => $this->definitionPayload(),
                'input' => [ 'query' => 'laravel' ],
                'bounded' => [ 'tenant_id' => 'tenant-123' ],
            ],
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
            uri: '/_superwire/tools/search',
            data: [
                'definition' => $this->definitionPayload(),
                'input' => [ 'query' => 'laravel' ],
                'bounded' => [ 'tenant_id' => 'tenant-123' ],
            ],
        );

        $response->assertUnauthorized();
    }

    public function test_it_returns_schema_validation_errors_as_tool_errors(): void
    {
        config()->set('superwire.tools.internal_token', 'internal-token');

        app(ToolRegistry::class)->register(tool: new SearchTool(), name: 'search');

        $response = $this->postJson(
            uri: '/_superwire/tools/search',
            data: [
                'definition' => $this->definitionPayload(),
                'input' => [],
                'bounded' => [ 'tenant_id' => 'tenant-123' ],
            ],
            headers: [ 'Authorization' => 'Bearer internal-token' ],
        );

        $response->assertStatus(422);
        $response->assertJsonFragment([ 'error' => 'Invalid tool `search` input: Object expected, [] received' ]);
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
