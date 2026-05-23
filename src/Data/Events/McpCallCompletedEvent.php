<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpCallCompletedEvent
{
    public function __construct(
        public string $operation,
        public string $targetName,
        public mixed $result,
        public ?string $serverName = null,
        public ?string $itemName = null,
        public mixed $arguments = null,
        public mixed $rawResult = null,
        public ?int $durationMs = null,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            operation: $data[ 'operation' ],
            targetName: $data[ 'target_name' ],
            result: $data[ 'result' ] ?? null,
            serverName: $data[ 'server_name' ] ?? null,
            itemName: $data[ 'item_name' ] ?? null,
            arguments: $data[ 'arguments' ] ?? $data[ 'params' ] ?? null,
            rawResult: $data[ 'raw_result' ] ?? $data[ 'output' ] ?? null,
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
            'result' => $this->result,
            'raw_result' => $this->rawResult,
            'duration_ms' => $this->durationMs,
        ];
    }
}
