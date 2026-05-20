<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpToolSchemaFetchCompletedEvent
{
    public function __construct(
        public string $serverName,
        public int $toolCount,
        public int $durationMs,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            serverName: $data[ 'server_name' ],
            toolCount: $data[ 'tool_count' ],
            durationMs: $data[ 'duration_ms' ],
        );
    }

    public function toArray(): array
    {
        return [
            'server_name' => $this->serverName,
            'tool_count' => $this->toolCount,
            'duration_ms' => $this->durationMs,
        ];
    }
}
