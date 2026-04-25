<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class OutputAbortTool implements Tool
{
    public function description(): string
    {
        return 'Call this tool only when it is impossible to produce a truthful final answer. Provide a clear reason.';
    }

    public function handle(Request $request): string
    {
        return json_encode([
            'superwire_output_abort' => true,
            'reason' => $request->all()[ 'reason' ] ?? 'The agent could not produce an answer.',
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->required(),
        ];
    }
}
