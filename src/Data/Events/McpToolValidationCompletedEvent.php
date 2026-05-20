<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpToolValidationCompletedEvent
{
    public function __construct(
        public string $toolName,
        public int $durationMs,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            toolName: $data[ 'tool_name' ],
            durationMs: $data[ 'duration_ms' ],
        );
    }

    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'duration_ms' => $this->durationMs,
        ];
    }
}
