<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Superwire\Laravel\Data\Agent\OutputField;
use Superwire\Laravel\Support\JsonSchemaFactory;

final readonly class OutputSuccessTool implements Tool
{
    public function __construct(
        private OutputField $field,
        private OutputSchemaTypeMapper $schemaTypeMapper = new OutputSchemaTypeMapper(),
    )
    {
    }

    public function description(): string
    {
        return 'Call this tool only when you have successfully produced the final answer matching the required output schema.';
    }

    public function handle(Request $request): string
    {
        $output = $this->field->isObject() ? $request->all() : ($request->all()[ 'value' ] ?? null);

        try {
            JsonSchemaFactory::validate(
                schema: $this->field->jsonSchema,
                value: $output,
                name: 'agent output tool input',
            );
        } catch (InvalidArgumentException $exception) {
            return json_encode([
                'error' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'superwire_output_success' => true,
            'output' => $output,
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        if ($this->field->isObject()) {
            return $this->schemaTypeMapper->schemaFields(fields: $this->field->fields(), schema: $schema);
        }

        return [
            'value' => $this->schemaTypeMapper->schemaType(field: $this->field, schema: $schema)->required(),
        ];
    }
}
