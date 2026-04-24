<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

final class LaravelAiSchema
{
    /**
     * @param array<string, mixed> $schema
     */
    public static function type(JsonSchema $factory, array $schema, bool $required = false): Type
    {
        $type = $schema[ 'type' ] ?? 'string';

        if (is_array($type)) {
            $type = array_values(array_filter($type, static fn (mixed $value): bool => $value !== 'null'))[0] ?? 'string';
        }

        $jsonType = match ($type) {
            'object' => $factory->object(self::properties($factory, $schema)),
            'array' => self::array($factory, $schema),
            'integer' => $factory->integer(),
            'number' => $factory->number(),
            'boolean' => $factory->boolean(),
            default => $factory->string(),
        };

        if (isset($schema[ 'description' ]) && is_string($schema[ 'description' ])) {
            $jsonType->description($schema[ 'description' ]);
        }

        if (isset($schema[ 'enum' ]) && is_array($schema[ 'enum' ])) {
            $jsonType->enum(array_values($schema[ 'enum' ]));
        }

        if (in_array('null', (array) ($schema[ 'type' ] ?? []), true)) {
            $jsonType->nullable();
        }

        if ($required) {
            $jsonType->required();
        }

        return $jsonType;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, Type>
     */
    public static function properties(JsonSchema $factory, array $schema): array
    {
        $properties = $schema[ 'properties' ] ?? [];
        $required = is_array($schema[ 'required' ] ?? null) ? $schema[ 'required' ] : [];
        $mapped = [];

        if (!is_array($properties)) {
            return [];
        }

        foreach ($properties as $name => $propertySchema) {
            if (!is_string($name) || !is_array($propertySchema)) {
                continue;
            }

            $mapped[ $name ] = self::type($factory, $propertySchema, in_array($name, $required, true));
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function array(JsonSchema $factory, array $schema): Type
    {
        $array = $factory->array();
        $items = $schema[ 'items' ] ?? null;

        if (is_array($items)) {
            $array->items(self::type($factory, $items));
        }

        return $array;
    }
}
