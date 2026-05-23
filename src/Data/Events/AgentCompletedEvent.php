<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class AgentCompletedEvent
{
    public function __construct(
        public mixed $output,
        public int $durationMs = 0,
        public ?int $iterationIndex = null,
        public bool $cacheHit = false,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            output: $data[ 'output' ] ?? null,
            durationMs: $data[ 'duration_ms' ] ?? 0,
            iterationIndex: $data[ 'iteration_index' ] ?? null,
            cacheHit: $data[ 'cache_hit' ] ?? false,
        );
    }

    public function toArray(): array
    {
        $data = [
            'output' => $this->output,
        ];

        if ($this->durationMs > 0) {
            $data[ 'duration_ms' ] = $this->durationMs;
        }

        if ($this->iterationIndex !== null) {
            $data[ 'iteration_index' ] = $this->iterationIndex;
        }

        if ($this->cacheHit) {
            $data[ 'cache_hit' ] = true;
        }

        return $data;
    }
}
