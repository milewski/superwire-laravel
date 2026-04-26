<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime\Tool;

use InvalidArgumentException;
use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Runtime\Tool\ToolInvoker;
use Superwire\Laravel\Runtime\Tool\ToolRegistry;
use Superwire\Laravel\Tools\AbstractTool;
use Superwire\Laravel\Tests\Fixtures\Tools\SearchTool;
use Superwire\Laravel\Tests\Fixtures\Tools\TypedSearchTool;
use Superwire\Laravel\Tests\TestCase;

final class ToolInvokerTest extends TestCase
{
    public function test_it_validates_and_invokes_array_based_tools(): void
    {
        $tool = new SearchTool();
        $registry = new ToolRegistry();
        $registry->register(tool: $tool, name: 'search');

        $result = new ToolInvoker()->invoke(
            tool: $registry->get(name: 'search'),
            definition: $this->toolDefinition(name: 'search'),
            input: [ 'query' => 'laravel' ],
            bounded: [ 'tenant_id' => 'tenant-123' ],
        );

        $this->assertSame(
            expected: [ 'query' => 'laravel', 'tenant' => 'tenant-123' ],
            actual: $result,
        );

        $this->assertSame(
            expected: [ [ [ 'query' => 'laravel' ], [ 'tenant_id' => 'tenant-123' ] ] ],
            actual: $tool->calls,
        );
    }

    public function test_it_hydrates_typed_handle_parameters(): void
    {
        $registry = new ToolRegistry();
        $registry->register(tool: new TypedSearchTool(), name: 'typed_search');

        $result = new ToolInvoker()->invoke(
            tool: $registry->get(name: 'typed_search'),
            definition: $this->toolDefinition(name: 'typed_search'),
            input: [ 'query' => 'laravel' ],
            bounded: [ 'tenant_id' => 'tenant-123' ],
        );

        $this->assertSame(
            expected: [ 'query' => 'laravel', 'tenant' => 'tenant-123' ],
            actual: $result,
        );
    }

    public function test_it_rejects_invalid_agent_input_before_calling_tool(): void
    {
        $tool = new SearchTool();
        $registry = new ToolRegistry();
        $registry->register(tool: $tool, name: 'search');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tool `search` input');

        new ToolInvoker()->invoke(
            tool: $registry->get(name: 'search'),
            definition: $this->toolDefinition(name: 'search'),
            input: [],
            bounded: [ 'tenant_id' => 'tenant-123' ],
        );
    }

    public function test_it_validates_empty_object_tool_inputs(): void
    {
        $tool = new class extends AbstractTool {
            public function handle(array $input, array $bounded): array
            {
                return [ 'input' => $input, 'bounded' => $bounded ];
            }
        };

        $result = new ToolInvoker()->invoke(
            tool: $tool,
            definition: ToolDefinition::fromArray([
                'name' => 'empty_input',
                'description' => 'Empty input',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                    'additionalProperties' => false,
                ],
                'bounded_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ]),
            input: [],
            bounded: [],
        );

        $this->assertSame(expected: [ 'input' => [], 'bounded' => [] ], actual: $result);
    }

    private function toolDefinition(string $name): ToolDefinition
    {
        return ToolDefinition::fromArray([
            'name' => $name,
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
        ]);
    }
}
