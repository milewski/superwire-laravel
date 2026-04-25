<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime\Tool;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Runtime\Tool\BoundToolDefinition;
use Superwire\Laravel\Runtime\Tool\LaravelAiTool;
use Superwire\Laravel\Tests\TestCase;

final class LaravelAiToolTest extends TestCase
{
    public function test_it_invokes_internal_endpoint_through_laravel_http(): void
    {
        config()->set('superwire.tools.internal_token', 'internal-token');

        $toolUrl = route('superwire.tools.invoke', [
            'workflow' => 'run-1',
            'agent' => 'assistant',
            'tool' => 'search',
        ]);

        Http::fake([
            $toolUrl => Http::response([
                'result' => [ 'answer' => 'found' ],
            ]),
        ]);

        $result = new LaravelAiTool(tool: $this->boundTool())->handle(
            request: new Request([ 'query' => 'laravel' ]),
        );

        $this->assertSame(
            expected: json_encode([ 'answer' => 'found' ]),
            actual: $result,
        );

        Http::assertSent(function (ClientRequest $request) use ($toolUrl): bool {
            return $request->url() === $toolUrl
                && $request->hasHeader('Authorization', 'Bearer internal-token')
                && $request[ 'input' ] === [ 'query' => 'laravel' ];
        });
    }

    private function boundTool(): BoundToolDefinition
    {
        return new BoundToolDefinition(
            definition: ToolDefinition::fromArray([
                'name' => 'search',
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
                    'properties' => [],
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ]),
            bounded: [],
            runId: 'run-1',
            agentName: 'assistant',
        );
    }
}
