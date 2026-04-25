<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

final class JsonSchemaTypeMapper
{
    public function type(array $schemaDefinition, JsonSchema $schema): Type
    {
        $type = $schemaDefinition[ 'type' ] ?? 'string';

        return match ($type) {
            'integer' => $schema->integer(),
            'number' => $schema->number(),
            'boolean' => $schema->boolean(),
            'array' => $schema->array()->items($this->type(
                schemaDefinition: is_array($schemaDefinition[ 'items' ] ?? null) ? $schemaDefinition[ 'items' ] : [ 'type' => 'string' ],
                schema: $schema,
            )),
            'object' => $schema->object($this->properties(schemaDefinition: $schemaDefinition, schema: $schema)),
            default => $schema->string(),
        };
    }

    public function properties(array $schemaDefinition, JsonSchema $schema): array
    {
        $properties = is_array($schemaDefinition[ 'properties' ] ?? null) ? $schemaDefinition[ 'properties' ] : [];
        $required = is_array($schemaDefinition[ 'required' ] ?? null) ? $schemaDefinition[ 'required' ] : [];
        $mapped = [];

        foreach ($properties as $name => $propertySchema) {

            if (!is_string($name) || !is_array($propertySchema)) {
                continue;
            }

            $type = $this->type(schemaDefinition: $propertySchema, schema: $schema);

            if (in_array($name, $required, true)) {
                $type->required();
            }

            $mapped[ $name ] = $type;

        }

        return $mapped;
    }
}
