<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Superwire\Laravel\Data\Agent\OutputField;
use Superwire\Laravel\Runtime\AgentInvocation;

final readonly class StructuredOutputStrategy
{
    public function agent(OutputField $field, AgentInvocation $invocation, array $tools, OutputSchemaTypeMapper $schemaTypeMapper): AnonymousAgent
    {
        if ($field->isObject()) {
            return new SuperwireStructuredAnonymousAgent(
                instructions: '',
                messages: [],
                tools: $tools,
                schema: fn (JsonSchemaTypeFactory $schema): array => $schemaTypeMapper->schemaFields(fields: $field->fields(), schema: $schema),
            );
        }

        return new SuperwireAnonymousAgent(instructions: '', messages: [], tools: $tools);
    }

    public function output(mixed $response): array | string
    {
        return $response instanceof StructuredAgentResponse ? $response->structured : $response->text;
    }
}
