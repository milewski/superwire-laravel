<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class ContextCompactionFailedEvent
{
    public function __construct(
        public string $message,
        public int $durationMs = 0,
    )
    {
    }

    public static function fromArray(string $message, array $data): self
    {
        return new self(
            message: $message,
            durationMs: $data[ 'duration_ms' ] ?? 0,
        );
    }

    public function toArray(): array
    {
        $data = [];

        if ($this->durationMs > 0) {
            $data[ 'duration_ms' ] = $this->durationMs;
        }

        return $data;
    }
}
