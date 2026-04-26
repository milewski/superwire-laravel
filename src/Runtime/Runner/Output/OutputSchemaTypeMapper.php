<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner\Output;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Superwire\Laravel\Data\Agent\OutputField;

final readonly class OutputSchemaTypeMapper
{
    public function schemaFields(array $fields, JsonSchema $schema): array
    {
        $schemaFields = [];

        foreach ($fields as $name => $fieldType) {

            if (!is_string($name) || !is_array($fieldType)) {
                continue;
            }

            $schemaFields[ $name ] = $this->schemaType(field: OutputField::fromWorkflowType($fieldType), schema: $schema)->required();

        }

        return $schemaFields;
    }

    public function schemaType(OutputField $field, JsonSchema $schema): Type
    {
        return match ($field->kind()) {
            'string' => $schema->string(),
            'integer' => $schema->integer(),
            'number', 'float' => $schema->number(),
            'boolean' => $schema->boolean(),
            'null' => $schema->string()->nullable(),
            'string_enum' => $schema->string()->enum($field->enumValues()),
            'array' => $this->arraySchemaType(field: $field, schema: $schema),
            'tuple' => $schema->array(),
            'object' => $schema->object($this->schemaFields(fields: $field->fields(), schema: $schema)),
            'union' => $this->unionSchemaType(field: $field, schema: $schema),
            default => throw new InvalidArgumentException('Unsupported structured output type.'),
        };
    }

    private function arraySchemaType(OutputField $field, JsonSchema $schema): Type
    {
        $type = $schema->array()->items($this->schemaType(field: $field->itemType(), schema: $schema));

        if ($field->fixedLength() !== null) {
            $type->min($field->fixedLength())->max($field->fixedLength());
        }

        return $type;
    }

    private function unionSchemaType(OutputField $field, JsonSchema $schema): Type
    {
        $members = $field->unionMembers();
        $nonNullMembers = array_values(array_filter(
            array: $members,
            callback: fn (OutputField $member): bool => $member->kind() !== 'null',
        ));

        if (count($nonNullMembers) === 1 && count($members) === 2) {
            return $this->schemaType(field: $nonNullMembers[ 0 ], schema: $schema)->nullable();
        }

        return $schema->string();
    }
}
