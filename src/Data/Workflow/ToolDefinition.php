<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Workflow;

use InvalidArgumentException;
use Superwire\Laravel\Data\Concerns\ValidatesPayload;
use Superwire\Laravel\Support\JsonSchemaFactory;
use Swaggest\JsonSchema\Schema;

final class ToolDefinition
{
    use ValidatesPayload;

    /**
     * @param array<string, mixed> $inputSchemaDefinition
     * @param array<string, mixed> $boundedSchemaDefinition
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $inputSchemaDefinition,
        public readonly Schema $inputSchema,
        public readonly array $boundedSchemaDefinition,
        public readonly Schema $boundedSchema,
    )
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $description = $payload['description'] ?? null;

        if (!is_string($description) && $description !== null) {
            throw new InvalidArgumentException('description must be a string or null');
        }

        $inputSchemaDefinition = self::array($payload, 'input_schema');
        $boundedSchemaDefinition = self::array($payload, 'bounded_schema');

        return new self(
            name: self::string($payload, 'name'),
            description: $description,
            inputSchemaDefinition: $inputSchemaDefinition,
            inputSchema: JsonSchemaFactory::fromArray($inputSchemaDefinition, 'tool input schema'),
            boundedSchemaDefinition: $boundedSchemaDefinition,
            boundedSchema: JsonSchemaFactory::fromArray($boundedSchemaDefinition, 'tool bounded schema'),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function validateAgentArguments(array $arguments): void
    {
        JsonSchemaFactory::validate($this->inputSchema, $arguments, sprintf('tool `%s` input', $this->name));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function validateBoundArguments(array $arguments): void
    {
        JsonSchemaFactory::validate($this->boundedSchema, $arguments, sprintf('tool `%s` bound arguments', $this->name));
    }

    /**
     * @return array<int, array{name: string, schema: array<string, mixed>, required: bool}>
     */
    public function prismInputParameters(): array
    {
        $properties = $this->inputSchemaDefinition['properties'] ?? [];
        $requiredProperties = $this->requiredProperties($this->inputSchemaDefinition);
        $parameters = [];

        if (!is_array($properties)) {
            return [];
        }

        foreach ($properties as $propertyName => $propertySchema) {

            if (!is_string($propertyName) || !is_array($propertySchema)) {
                continue;
            }

            $parameters[] = [
                'name' => $propertyName,
                'schema' => $propertySchema,
                'required' => in_array($propertyName, $requiredProperties, true),
            ];

        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     * @return array<int, string>
     */
    private function requiredProperties(array $schemaDefinition): array
    {
        $requiredProperties = $schemaDefinition['required'] ?? [];

        if (!is_array($requiredProperties)) {
            return [];
        }

        return array_values(array_filter($requiredProperties, is_string(...)));
    }
}
