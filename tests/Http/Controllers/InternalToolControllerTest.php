<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Http\Controllers;

use Superwire\Laravel\Tests\Fixtures\Tools\SearchTool;
use Superwire\Laravel\Tests\TestCase;

final class InternalToolControllerTest extends TestCase
{
    public function test_it_invokes_registered_tools_behind_internal_token(): void
    {
        config()->set('superwire.tools.internal_token', 'internal-token');

        $response = $this->postJson(
            uri: '/_superwire/a/assistant/t/search',
            data: $this->toolRequestPayload(input: [ 'query' => 'laravel' ]),
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
            uri: '/_superwire/a/assistant/t/search',
            data: [
                'input' => [ 'query' => 'laravel' ],
            ],
        );

        $response->assertUnauthorized();
    }

    public function test_it_returns_schema_validation_errors_as_tool_errors(): void
    {
        config()->set('superwire.tools.internal_token', 'internal-token');

        $response = $this->postJson(
            uri: '/_superwire/a/assistant/t/search',
            data: $this->toolRequestPayload(input: []),
            headers: [ 'Authorization' => 'Bearer internal-token' ],
        );

        $response->assertStatus(422);
        $response->assertJsonFragment([ 'error' => 'Invalid tool `search` input: Required property missing: query, data: []' ]);
    }

    public function test_it_rejects_unknown_workflow_tools(): void
    {
        config()->set('superwire.tools.internal_token', 'internal-token');

        $response = $this->postJson(
            uri: '/_superwire/a/assistant/t/missing_tool',
            data: $this->toolRequestPayload(input: [ 'query' => 'laravel' ]),
            headers: [ 'Authorization' => 'Bearer internal-token' ],
        );

        $response->assertStatus(422);
        $response->assertJsonFragment([ 'error' => 'Tool `missing_tool` is not defined in workflow `' . __DIR__ . '/../../Stubs/search_tool.wire`.' ]);
    }

    private function toolRequestPayload(array $input): array
    {
        return [
            'input' => $input,
            'workflow_path' => __DIR__ . '/../../Stubs/search_tool.wire',
            'tool_class' => SearchTool::class,
            'bounded' => [ 'tenant_id' => 'tenant-123' ],
        ];
    }
}
