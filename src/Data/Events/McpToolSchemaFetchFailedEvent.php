<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpToolSchemaFetchFailedEvent
{
    public function __construct(
        public string $serverName,
        public mixed $error,
        public int $durationMs,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            serverName: $data[ 'server_name' ],
            error: $data[ 'error' ] ?? null,
            durationMs: $data[ 'duration_ms' ],
        );
    }

    public function toArray(): array
    {
        return [
            'server_name' => $this->serverName,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
        ];
    }
}
