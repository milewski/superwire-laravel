<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpToolValidationStartedEvent
{
    public function __construct(
        public string $toolName,
        public mixed $arguments,
        public mixed $inputSchema,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            toolName: $data[ 'tool_name' ],
            arguments: $data[ 'arguments' ] ?? $data[ 'params' ] ?? null,
            inputSchema: $data[ 'input_schema' ] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'arguments' => $this->arguments,
            'params' => $this->arguments,
            'input_schema' => $this->inputSchema,
        ];
    }
}
