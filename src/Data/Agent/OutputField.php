<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Agent;

use Superwire\Laravel\Data\Concerns\ValidatesPayload;
use Superwire\Laravel\Support\JsonSchemaFactory;
use Swaggest\JsonSchema\Schema;

final class OutputField
{
    use ValidatesPayload;

    public function __construct(
        public readonly array $workflowType,
        public readonly Schema $jsonSchema,
    )
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            workflowType: self::array($payload, 'workflow_type'),
            jsonSchema: JsonSchemaFactory::fromArray(self::array($payload, 'json_schema'), 'output field data'),
        );
    }

    public function kind(): ?string
    {
        $kind = $this->workflowType[ 'kind' ] ?? null;

        return is_string($kind) ? $kind : null;
    }

    public function isString(): bool
    {
        return $this->kind() === 'string';
    }

    public function isObject(): bool
    {
        return $this->kind() === 'object';
    }

    public function isArray(): bool
    {
        return $this->kind() === 'array';
    }

    public function isUnion(): bool
    {
        return $this->kind() === 'union';
    }

    public function isTuple(): bool
    {
        return $this->kind() === 'tuple';
    }

    public function isStringEnum(): bool
    {
        return $this->kind() === 'string_enum';
    }

    public function fields(): array
    {
        $fields = $this->workflowType[ 'fields' ] ?? [];

        return is_array($fields) ? $fields : [];
    }

    public function itemType(): self
    {
        $itemType = $this->workflowType[ 'item_type' ] ?? [ 'kind' => 'string' ];

        return self::fromWorkflowType(is_array($itemType) ? $itemType : [ 'kind' => 'string' ]);
    }

    public function fixedLength(): ?int
    {
        $fixedLength = $this->workflowType[ 'fixed_length' ] ?? null;

        return is_int($fixedLength) ? $fixedLength : null;
    }

    public function tupleItems(): array
    {
        $items = $this->workflowType[ 'items' ] ?? [];

        if (!is_array($items)) {
            return [];
        }

        return array_map(
            callback: fn (array $item): self => self::fromWorkflowType(workflowType: $item),
            array: array_values(array_filter($items, is_array(...))),
        );
    }

    public function unionMembers(): array
    {
        $members = $this->workflowType[ 'members' ] ?? [];

        if (!is_array($members)) {
            return [];
        }

        return array_map(
            callback: fn (array $member): self => self::fromWorkflowType(workflowType: $member),
            array: array_values(array_filter($members, is_array(...))),
        );
    }

    public function enumValues(): array
    {
        $values = $this->workflowType[ 'values' ] ?? [];

        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }

    public static function fromWorkflowType(array $workflowType): self
    {
        return new self(
            workflowType: $workflowType,
            jsonSchema: JsonSchemaFactory::fromArray([ 'type' => 'string' ], 'output field workflow type placeholder'),
        );
    }
}
