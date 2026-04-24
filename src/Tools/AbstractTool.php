<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tools;

use Illuminate\Support\Str;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use RuntimeException;
use Superwire\Laravel\Contracts\BoundInput;
use Superwire\Laravel\Contracts\ToolInput;
use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Support\JsonSchemaFactory;
use Superwire\Laravel\Support\LaravelAiSchema;
use Superwire\Laravel\Tools\Concerns\InfersToolInputSchemas;
use Superwire\Laravel\Tools\Concerns\ReflectsToolSignature;
use Superwire\Laravel\Tools\Concerns\ResolvesToolDescriptions;
use Throwable;

abstract class AbstractTool implements WorkflowTool
{
    use InfersToolInputSchemas;
    use ReflectsToolSignature;
    use ResolvesToolDescriptions;

    public static function name(): string
    {
        return Str::snake(class_basename(static::class));
    }

    public static function description(): string
    {
        $description = static::descriptionFromClassAttributes();

        if ($description !== null) {
            return $description;
        }

        return sprintf('Use `%s` to complete this action.', Str::headline(class_basename(static::class)));
    }

    public function toAiTool(array $boundArguments = []): Tool
    {
        return LaravelAiToolFactory::make(
            name: static::name(),
            description: static::description(),
            handler: function (array $agentArguments) use ($boundArguments): string {

            $result = $this->execute(
                agentInput: static::resolveAgentInput($agentArguments),
                boundInput: static::resolveBoundInput($boundArguments),
            );

            return json_encode($result, JSON_THROW_ON_ERROR);

            },
            schema: fn (JsonSchema $schema): array => $this->agentInputSchemaTypes($schema),
        );
    }

    public function toAiToolFromDefinition(ToolDefinition $toolDefinition, array $boundArguments = []): Tool
    {
        return LaravelAiToolFactory::make(
            name: static::name(),
            description: $toolDefinition->description ?? static::description(),
            handler: function (array $agentArguments) use ($boundArguments, $toolDefinition): string {

                try {

                    $toolDefinition->validateAgentArguments($agentArguments);
                    $toolDefinition->validateBoundArguments($boundArguments);

                    $result = $this->execute(
                        agentInput: static::resolveAgentInput($agentArguments),
                        boundInput: static::resolveBoundInput($boundArguments),
                    );

                    return json_encode($result, JSON_THROW_ON_ERROR);

                } catch (Throwable $throwable) {

                if ($throwable instanceof RuntimeException) {

                    return sprintf(
                        'Tool execution error: %s. This error occurred during tool execution, not due to invalid parameters.',
                        $throwable->getMessage(),
                    );

                }

                return $throwable->getMessage();

                }
            },
            schema: fn (JsonSchema $schema): array => $this->toolDefinitionSchemaTypes($schema, $toolDefinition),
        );
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    private function agentInputSchemaTypes(JsonSchema $schema): array
    {
        $parameters = [];

        foreach ($this->agentInputSchemas() as $parameterSchema) {
            $parameters[ $parameterSchema[ 'name' ] ] = LaravelAiSchema::type(
                $schema,
                JsonSchemaFactory::toArray($parameterSchema[ 'schema' ]),
                $parameterSchema[ 'required' ],
            );
        }

        return $parameters;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    private function toolDefinitionSchemaTypes(JsonSchema $schema, ToolDefinition $toolDefinition): array
    {
        $parameters = [];

        foreach ($toolDefinition->inputParameters() as $parameterSchema) {
            $parameters[ $parameterSchema[ 'name' ] ] = LaravelAiSchema::type(
                $schema,
                $parameterSchema[ 'schema' ],
                $parameterSchema[ 'required' ],
            );
        }

        return $parameters;
    }

    public function execute(mixed $agentInput = null, mixed $boundInput = null): array
    {
        $executionMethod = static::executionMethodReflection();
        $arguments = [];

        foreach ($executionMethod->getParameters() as $parameter) {

            $parameterClass = static::parameterClassFromReflection($parameter);

            if ($parameterClass !== null && is_a($parameterClass, ToolInput::class, true)) {

                $arguments[] = $agentInput;

                continue;

            }

            if ($parameterClass !== null && is_a($parameterClass, BoundInput::class, true)) {

                $arguments[] = $boundInput;

                continue;

            }

            throw new RuntimeException(
                message: sprintf(
                    'Tool `%s` has unsupported execution parameter `%s`. Use %s or %s implementations only.', static::name(), $parameter->getName(), ToolInput::class, BoundInput::class,
                ),
            );

        }

        logger($executionMethod->class, $arguments);

        $result = $this->{$executionMethod->getName()}(...$arguments);

        if (!is_array($result)) {
            throw new RuntimeException(sprintf('Tool `%s` must return an array from handle().', static::class));
        }

        return $result;
    }
}
