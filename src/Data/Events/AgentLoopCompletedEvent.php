<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class AgentLoopCompletedEvent
{
    public function __construct(
        public mixed $output,
        public int $durationMs,
        public int $iterationCount,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            output: $data[ 'output' ] ?? null,
            durationMs: $data[ 'duration_ms' ] ?? 0,
            iterationCount: $data[ 'iteration_count' ] ?? 0,
        );
    }

    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'duration_ms' => $this->durationMs,
            'iteration_count' => $this->iterationCount,
        ];
    }
}
