<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpCallStartedEvent
{
    public function __construct(
        public string $operation,
        public string $targetName,
        public mixed $arguments,
        public ?string $serverName = null,
        public ?string $itemName = null,
        public mixed $inputSchema = null,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            operation: $data[ 'operation' ],
            targetName: $data[ 'target_name' ],
            arguments: $data[ 'arguments' ] ?? $data[ 'params' ] ?? null,
            serverName: $data[ 'server_name' ] ?? null,
            itemName: $data[ 'item_name' ] ?? null,
            inputSchema: $data[ 'input_schema' ] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'target_name' => $this->targetName,
            'server_name' => $this->serverName,
            'item_name' => $this->itemName,
            'arguments' => $this->arguments,
            'params' => $this->arguments,
            'input_schema' => $this->inputSchema,
        ];
    }
}
