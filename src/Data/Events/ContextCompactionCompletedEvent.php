<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class ContextCompactionCompletedEvent
{
    public function __construct(
        public mixed $output,
        public int $durationMs = 0,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            output: $data[ 'output' ] ?? null,
            durationMs: $data[ 'duration_ms' ] ?? 0,
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

        return $data;
    }
}
