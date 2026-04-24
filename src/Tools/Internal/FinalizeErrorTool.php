<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tools\Internal;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Superwire\Laravel\Tools\LaravelAiToolFactory;
use Superwire\Laravel\Tools\WorkflowTool;

final class FinalizeErrorTool implements WorkflowTool
{
    private bool $wasCalled = false;

    private ?string $reason = null;

    public static function name(): string
    {
        return 'finalize_error';
    }

    public function toAiTool(array $boundArguments = []): Tool
    {
        return LaravelAiToolFactory::make(
            name: self::name(),
            description: 'Finish the agent with an error message when the task cannot be completed.',
            handler: function (array $arguments): string {

                $this->wasCalled = true;
                $this->reason = is_string($arguments[ 'message' ] ?? null) ? $arguments[ 'message' ] : null;

                return 'OK';

            },
            schema: fn (JsonSchema $schema): array => [
                'message' => $schema->string()->description('The reason the agent cannot complete successfully.')->required(),
            ],
        );
    }

    public function wasCalled(): bool
    {
        return $this->wasCalled;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function reset(): void
    {
        $this->wasCalled = false;
        $this->reason = null;
    }
}
