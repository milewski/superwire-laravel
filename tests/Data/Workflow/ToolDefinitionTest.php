<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Data\Workflow;

use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Tests\TestCase;

final class ToolDefinitionTest extends TestCase
{
    public function test_it_accepts_current_binding_schema_key(): void
    {
        $definition = ToolDefinition::fromArray([
            'name' => 'search',
            'description' => null,
            'input_schema' => $this->emptyObjectSchema(),
            'binding_schema' => [
                'type' => 'object',
                'properties' => [
                    'tenant_id' => [ 'type' => 'string' ],
                ],
                'required' => [ 'tenant_id' ],
                'additionalProperties' => false,
            ],
        ]);

        $this->assertSame(
            expected: [ 'tenant_id' ],
            actual: $definition->boundedSchemaDefinition[ 'required' ],
        );
    }

    public function test_it_accepts_legacy_bounded_schema_key(): void
    {
        $definition = ToolDefinition::fromArray([
            'name' => 'search',
            'description' => null,
            'input_schema' => $this->emptyObjectSchema(),
            'bounded_schema' => [
                'type' => 'object',
                'properties' => [
                    'tenant_id' => [ 'type' => 'string' ],
                ],
                'required' => [ 'tenant_id' ],
                'additionalProperties' => false,
            ],
        ]);

        $this->assertSame(
            expected: [ 'tenant_id' ],
            actual: $definition->boundedSchemaDefinition[ 'required' ],
        );
    }

    public function test_it_defaults_missing_bound_schema_to_empty_object_schema(): void
    {
        $definition = ToolDefinition::fromArray([
            'name' => 'search',
            'description' => null,
            'input_schema' => $this->emptyObjectSchema(),
            'bounded_schema' => null,
        ]);

        $this->assertSame(expected: 'object', actual: $definition->boundedSchemaDefinition[ 'type' ]);
        $this->assertSame(expected: [], actual: $definition->boundedSchemaDefinition[ 'properties' ]);
        $this->assertFalse(condition: $definition->boundedSchemaDefinition[ 'additionalProperties' ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyObjectSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];
    }
}
