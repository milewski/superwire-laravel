<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class ToolCallStartedEvent
{
    public function __construct(
        public string $toolName,
        public mixed $arguments,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            toolName: $data[ 'tool_name' ],
            arguments: $data[ 'arguments' ] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'arguments' => $this->arguments,
        ];
    }
}
