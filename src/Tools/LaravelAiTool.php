<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class LaravelAiTool implements Tool
{
    /**
     * @param Closure(array<string, mixed>): string $handler
     * @param Closure(JsonSchema): array<string, Type> $schema
     */
    public function __construct(
        private readonly string $description,
        private readonly Closure $handler,
        private readonly Closure $schema,
    )
    {
    }

    public function description(): Stringable|string
    {
        return $this->description;
    }

    public function handle(Request $request): Stringable|string
    {
        return ($this->handler)($request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return ($this->schema)($schema);
    }

    /**
     * @return array<string, mixed>
     */
    public function parametersAsArray(): array
    {
        $schema = (new ObjectSchema($this->schema(new JsonSchemaTypeFactory)))->toSchema();

        return $schema[ 'properties' ] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function requiredParameters(): array
    {
        $schema = (new ObjectSchema($this->schema(new JsonSchemaTypeFactory)))->toSchema();

        return $schema[ 'required' ] ?? [];
    }
}
