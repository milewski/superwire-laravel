<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner;

use Illuminate\Contracts\Config\Repository;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\StructuredAnonymousAgent;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Runtime\AgentInvocation;

final readonly class LaravelAiAgentRunner implements AgentRunner
{
    public function __construct(
        private AiManager $ai,
        private Repository $config,
    )
    {
    }

    public function run(AgentInvocation $invocation): array | string
    {
        $this->configureProvider(invocation: $invocation);

        $workflowType = $this->workflowType(invocation: $invocation);
        $provider = $this->ai->textProvider(name: $invocation->provider->name);
        $response = $provider->prompt(new AgentPrompt(
            agent: $this->agentForType(workflowType: $workflowType),
            prompt: $invocation->prompt,
            attachments: [],
            provider: $provider,
            model: $invocation->model,
        ));

        if ($response instanceof StructuredAgentResponse) {
            return $response->structured;
        }

        return $response->text;
    }

    private function agentForType(array $workflowType): AnonymousAgent
    {
        if (($workflowType[ 'kind' ] ?? null) !== 'object') {
            return new AnonymousAgent(
                instructions: '',
                messages: [],
                tools: [],
            );
        }

        return new StructuredAnonymousAgent(
            instructions: '',
            messages: [],
            tools: [],
            schema: fn (JsonSchemaTypeFactory $schema): array => $this->schemaFields(
                fields: $workflowType[ 'fields' ] ?? [],
                schema: $schema,
            ),
        );
    }

    private function schemaFields(array $fields, JsonSchemaTypeFactory $schema): array
    {
        $schemaFields = [];

        foreach ($fields as $name => $fieldType) {
            if (!is_string($name) || !is_array($fieldType)) {
                continue;
            }

            $schemaFields[ $name ] = $this->schemaType(workflowType: $fieldType, schema: $schema)->required();
        }

        return $schemaFields;
    }

    private function schemaType(array $workflowType, JsonSchemaTypeFactory $schema): Type
    {
        return match ($workflowType[ 'kind' ] ?? null) {
            'string' => $schema->string(),
            'integer' => $schema->integer(),
            'number', 'float' => $schema->number(),
            'boolean' => $schema->boolean(),
            'array' => $schema->array()->items($this->schemaType(
                workflowType: is_array($workflowType[ 'item_type' ] ?? null) ? $workflowType[ 'item_type' ] : [ 'kind' => 'string' ],
                schema: $schema,
            )),
            'object' => $schema->object($this->schemaFields(
                fields: is_array($workflowType[ 'fields' ] ?? null) ? $workflowType[ 'fields' ] : [],
                schema: $schema,
            )),
            default => throw new InvalidArgumentException('Unsupported structured output type.'),
        };
    }

    private function workflowType(AgentInvocation $invocation): array
    {
        return $invocation->agent->runsForEach()
            ? $invocation->agent->output->iteration->workflowType
            : $invocation->agent->output->finalOutput->workflowType;
    }

    private function configureProvider(AgentInvocation $invocation): void
    {
        $this->config->set(
            key: 'ai.providers.' . $invocation->provider->name,
            value: $invocation->providerConfig,
        );

        $this->ai->purge(name: $invocation->provider->name);
    }
}
