<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime\Tool;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Superwire\Laravel\Runtime\Tool\JsonSchemaTypeMapper;
use Superwire\Laravel\Tests\TestCase;

final class JsonSchemaTypeMapperTest extends TestCase
{
    public function test_it_preserves_string_enum_values(): void
    {
        $mappedType = new JsonSchemaTypeMapper()->type(
            schemaDefinition: [
                'type' => 'string',
                'enum' => [ 'en_US', 'fr', 'zh_CN' ],
            ],
            schema: new JsonSchemaTypeFactory(),
        );

        $this->assertSame(
            expected: [
                'enum' => [ 'en_US', 'fr', 'zh_CN' ],
                'type' => 'string',
            ],
            actual: $mappedType->toArray(),
        );
    }
}
