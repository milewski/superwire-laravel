<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

final readonly class AgentRunResult
{
    public function __construct(
        public array|string $output,
        public array $history = [],
    )
    {
    }
}
