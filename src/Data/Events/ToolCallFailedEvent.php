<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class ToolCallFailedEvent
{
    public function __construct(
        public string $toolName,
        public mixed $error,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            toolName: $data[ 'tool_name' ],
            error: $data[ 'error' ] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'error' => $this->error,
        ];
    }
}
