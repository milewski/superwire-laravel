<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tools\Internal;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Superwire\Laravel\Support\LaravelAiSchema;
use Superwire\Laravel\Tools\LaravelAiToolFactory;
use Superwire\Laravel\Tools\WorkflowTool;

class FinalizeSuccessTool implements WorkflowTool
{
    private bool $wasCalled = false;
    private mixed $result = null;

    /**
     * @param array<string, mixed> $outputSchema
     */
    public function __construct(
        private readonly array $outputSchema,
    )
    {
    }

    public static function name(): string
    {
        return 'finalize_success';
    }

    public function toAiTool(array $boundArguments = []): Tool
    {
        return LaravelAiToolFactory::make(
            name: static::name(),
            description: 'Finish the agent successfully with the final output payload.',
            handler: function (array $arguments): string {

                $this->wasCalled = true;
                $this->result = $arguments[ 'result' ] ?? null;

                return 'OK';

            },
            schema: fn (JsonSchema $schema): array => [
                'result' => LaravelAiSchema::type($schema, $this->outputSchema, true),
            ],
        );
    }

    public function wasCalled(): bool
    {
        return $this->wasCalled;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function reset(): void
    {
        $this->wasCalled = false;
        $this->result = null;
    }
}
