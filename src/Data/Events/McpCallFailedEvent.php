<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpCallFailedEvent
{
    public function __construct(
        public string $operation,
        public string $targetName,
        public mixed $error,
        public ?string $serverName = null,
        public ?string $itemName = null,
        public mixed $arguments = null,
        public ?int $durationMs = null,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            operation: $data[ 'operation' ],
            targetName: $data[ 'target_name' ],
            error: $data[ 'error' ] ?? null,
            serverName: $data[ 'server_name' ] ?? null,
            itemName: $data[ 'item_name' ] ?? null,
            arguments: $data[ 'arguments' ] ?? $data[ 'params' ] ?? null,
            durationMs: $data[ 'duration_ms' ] ?? null,
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
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
        ];
    }
}
