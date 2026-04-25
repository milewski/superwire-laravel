<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use InvalidArgumentException;
use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Data\Agent\OutputField;

final class OutputParser
{
    public function parse(string | array $output, OutputField $field, Agent $agent): array | string | int | float | bool | null
    {
        return $this->parseValue(
            value: $output,
            workflowType: $field->workflowType,
            agentName: $agent->name,
        );
    }

    private function parseValue(string | array | int | float | bool | null $value, array $workflowType, string $agentName): array | string | int | float | bool | null
    {
        return match ($workflowType[ 'kind' ] ?? null) {
            'string' => $this->parseString(value: $value, agentName: $agentName),
            'integer' => $this->parseInteger(value: $value, agentName: $agentName),
            'number', 'float' => $this->parseNumber(value: $value, agentName: $agentName),
            'boolean' => $this->parseBoolean(value: $value, agentName: $agentName),
            'null' => $this->parseNull(value: $value, agentName: $agentName),
            'string_enum' => $this->parseStringEnum(value: $value, workflowType: $workflowType, agentName: $agentName),
            'array' => $this->parseArray(value: $value, workflowType: $workflowType, agentName: $agentName),
            'tuple' => $this->parseTuple(value: $value, workflowType: $workflowType, agentName: $agentName),
            'object' => $this->parseObject(value: $value, workflowType: $workflowType, agentName: $agentName),
            'union' => $this->parseUnion(value: $value, workflowType: $workflowType, agentName: $agentName),
            default => throw new InvalidArgumentException(sprintf('Agent `%s` declares an unsupported output type.', $agentName)),
        };
    }

    private function parseString(string | array | int | float | bool | null $value, string $agentName): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as a string.', $agentName));
        }

        return trim($value);
    }

    private function parseInteger(string | array | int | float | bool | null $value, string $agentName): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || filter_var(trim($value), FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as an integer.', $agentName));
        }

        return (int) trim($value);
    }

    private function parseNumber(string | array | int | float | bool | null $value, string $agentName): int | float
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

    private function parseBoolean(string | array | int | float | bool | null $value, string $agentName): bool
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

    private function parseNull(string | array | int | float | bool | null $value, string $agentName): null
    {
        if ($value === null || (is_string($value) && strtolower(trim($value)) === 'null')) {
            return null;
        }

        throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as null.', $agentName));
    }

    private function parseStringEnum(string | array | int | float | bool | null $value, array $workflowType, string $agentName): string
    {
        $text = $this->parseString(value: $value, agentName: $agentName);
        $values = is_array($workflowType[ 'values' ] ?? null) ? $workflowType[ 'values' ] : [];

        if (!in_array($text, $values, true)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that is not an allowed enum value.', $agentName));
        }

        return $text;
    }

    private function parseArray(string | array | int | float | bool | null $value, array $workflowType, string $agentName): array
    {
        $array = $this->arrayValue(value: $value, agentName: $agentName, expectedType: 'array');

        if (!array_is_list($array)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as an array.', $agentName));
        }

        $fixedLength = $workflowType[ 'fixed_length' ] ?? null;

        if (is_int($fixedLength) && count($array) !== $fixedLength) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that does not match the fixed array length.', $agentName));
        }

        $itemType = is_array($workflowType[ 'item_type' ] ?? null) ? $workflowType[ 'item_type' ] : [ 'kind' => 'string' ];

        return array_map(
            callback: fn (string | array | int | float | bool | null $item): array | string | int | float | bool | null => $this->parseValue(
                value: $item,
                workflowType: $itemType,
                agentName: $agentName,
            ),
            array: $array,
        );
    }

    private function parseTuple(string | array | int | float | bool | null $value, array $workflowType, string $agentName): array
    {
        $array = $this->arrayValue(value: $value, agentName: $agentName, expectedType: 'tuple');
        $items = is_array($workflowType[ 'items' ] ?? null) ? $workflowType[ 'items' ] : [];

        if (!array_is_list($array) || count($array) !== count($items)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as a tuple.', $agentName));
        }

        $tuple = [];

        foreach ($array as $index => $item) {
            $tuple[] = $this->parseValue(
                value: $item,
                workflowType: is_array($items[ $index ] ?? null) ? $items[ $index ] : [ 'kind' => 'string' ],
                agentName: $agentName,
            );
        }

        return $tuple;
    }

    private function parseObject(string | array | int | float | bool | null $value, array $workflowType, string $agentName): array
    {
        $array = $this->arrayValue(value: $value, agentName: $agentName, expectedType: 'object');

        if (array_is_list($array)) {
            throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as an object.', $agentName));
        }

        $fields = is_array($workflowType[ 'fields' ] ?? null) ? $workflowType[ 'fields' ] : [];
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
                workflowType: $fieldType,
                agentName: $agentName,
            );

        }

        return $object;
    }

    private function parseUnion(string | array | int | float | bool | null $value, array $workflowType, string $agentName): array | string | int | float | bool | null
    {
        $members = is_array($workflowType[ 'members' ] ?? null) ? $workflowType[ 'members' ] : [];

        foreach ($members as $member) {

            if (!is_array($member)) {
                continue;
            }

            try {
                return $this->parseValue(value: $value, workflowType: $member, agentName: $agentName);
            } catch (InvalidArgumentException) {
            }

        }

        throw new InvalidArgumentException(sprintf('Agent `%s` returned output that cannot be parsed as any union member.', $agentName));
    }

    private function arrayValue(string | array | int | float | bool | null $value, string $agentName, string $expectedType): array
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
