<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class ToolCallCompletedEvent
{
    public function __construct(
        public string $toolName,
        public mixed $result,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            toolName: $data[ 'tool_name' ],
            result: $data[ 'result' ] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'result' => $this->result,
        ];
    }
}
