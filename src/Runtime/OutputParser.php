<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use InvalidArgumentException;
use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Data\Agent\OutputField;

final class OutputParser
{
    public function parse(string|array $output, OutputField $field, Agent $agent): array|string|int|float|bool|null
    {
        return $this->parseValue(
            value: $output,
            field: $field,
            agentName: $agent->name,
        );
    }

    private function parseValue(string|array|int|float|bool|null $value, OutputField $field, string $agentName): array|string|int|float|bool|null
    {
        return match ($field->kind()) {
            'string' => $this->parseString(value: $value, agentName: $agentName),
            'integer' => $this->parseInteger(value: $value, agentName: $agentName),
            'number', 'float' => $this->parseNumber(value: $value, agentName: $agentName),
            'boolean' => $this->parseBoolean(value: $value, agentName: $agentName),
            'null' => $this->parseNull(value: $value, agentName: $agentName),
            'string_enum' => $this->parseStringEnum(value: $value, field: $field, agentName: $agentName),
            'array' => $this->parseArray(value: $value, field: $field, agentName: $agentName),
            'tuple' => $this->parseTuple(value: $value, field: $field, agentName: $agentName),
            'object' => $this->parseObject(value: $value, field: $field, agentName: $agentName),
            'union' => $this->parseUnion(value: $value, field: $field, agentName: $agentName),
            default => throw new InvalidArgumentException(sprintf('Agent `%s` declares an unsupported output type.', $agentName)),
        };
    }

    private function parseString(string|array|int|float|bool|null $value, string $agentName): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as a string.', $agentName));
        }

        return trim($value);
    }

    private function parseInteger(string|array|int|float|bool|null $value, string $agentName): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || filter_var(trim($value), FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as an integer.', $agentName));
        }

        return (int) trim($value);
    }

    private function parseNumber(string|array|int|float|bool|null $value, string $agentName): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_string($value) || !is_numeric(trim($value))) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as a number.', $agentName));
        }

        $text = trim($value);

        return str_contains($text, '.') ? (float) $text : (int) $text;
    }

    private function parseBoolean(string|array|int|float|bool|null $value, string $agentName): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as a boolean.', $agentName));
        }

        return match (strtolower(trim($value))) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as a boolean.', $agentName)),
        };
    }

    private function parseNull(string|array|int|float|bool|null $value, string $agentName): null
    {
        if ($value === null || (is_string($value) && strtolower(trim($value)) === 'null')) {
            return null;
        }

        throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as null.', $agentName));
    }

    private function parseStringEnum(string|array|int|float|bool|null $value, OutputField $field, string $agentName): string
    {
        $text = $this->parseString(value: $value, agentName: $agentName);

        if (!in_array($text, $field->enumValues(), true)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that is not an allowed enum value.', $agentName));
        }

        return $text;
    }

    private function parseArray(string|array|int|float|bool|null $value, OutputField $field, string $agentName): array
    {
        $array = $this->arrayValue(value: $value, agentName: $agentName, expectedType: 'array');

        if (!array_is_list($array)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as an array.', $agentName));
        }

        $fixedLength = $field->fixedLength();

        if (is_int($fixedLength) && count($array) !== $fixedLength) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that does not match the fixed array length.', $agentName));
        }

        return array_map(
            callback: fn (string|array|int|float|bool|null $item): array|string|int|float|bool|null => $this->parseValue(
                value: $item,
                field: $field->itemType(),
                agentName: $agentName,
            ),
            array: $array,
        );
    }

    private function parseTuple(string|array|int|float|bool|null $value, OutputField $field, string $agentName): array
    {
        $array = $this->arrayValue(value: $value, agentName: $agentName, expectedType: 'tuple');
        $items = $field->tupleItems();

        if (!array_is_list($array) || count($array) !== count($items)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as a tuple.', $agentName));
        }

        $tuple = [];

        foreach ($array as $index => $item) {

            $tuple[] = $this->parseValue(
                value: $item,
                field: $items[ $index ] ?? OutputField::fromWorkflowType([ 'kind' => 'string' ]),
                agentName: $agentName,
            );

        }

        return $tuple;
    }

    private function parseObject(string|array|int|float|bool|null $value, OutputField $field, string $agentName): array
    {
        $array = $this->arrayValue(value: $value, agentName: $agentName, expectedType: 'object');

        if (array_is_list($array)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as an object.', $agentName));
        }

        $fields = $field->fields();
        $object = $array;

        foreach ($fields as $name => $fieldType) {

            if (!is_string($name) || !is_array($fieldType)) {
                continue;
            }

            if (!array_key_exists($name, $array)) {
                throw new InvalidArgumentException(sprintf('Agent `%s` returned output that is missing object field `%s`.', $agentName, $name));
            }

            $object[ $name ] = $this->parseValue(
                value: $array[ $name ],
                field: OutputField::fromWorkflowType($fieldType),
                agentName: $agentName,
            );

        }

        return $object;
    }

    private function parseUnion(string|array|int|float|bool|null $value, OutputField $field, string $agentName): array|string|int|float|bool|null
    {
        foreach ($field->unionMembers() as $member) {

            try {

                return $this->parseValue(value: $value, field: $member, agentName: $agentName);

            } catch (InvalidArgumentException) {
            }

        }

        throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as any union member.', $agentName));
    }

    private function arrayValue(string|array|int|float|bool|null $value, string $agentName, string $expectedType): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as %s.', $agentName, $expectedType));
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as %s.', $agentName, $expectedType));
        }

        return $decoded;
    }
}
