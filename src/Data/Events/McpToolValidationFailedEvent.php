<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpToolValidationFailedEvent
{
    public function __construct(
        public string $toolName,
        public mixed $error,
        public int $durationMs,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            toolName: $data[ 'tool_name' ],
            error: $data[ 'error' ] ?? null,
            durationMs: $data[ 'duration_ms' ],
        );
    }

    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
        ];
    }
}
