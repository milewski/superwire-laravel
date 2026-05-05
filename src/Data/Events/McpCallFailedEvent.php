<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpCallFailedEvent
{
    public function __construct(
        public string $operation,
        public string $targetName,
        public mixed $error,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            operation: $data[ 'operation' ],
            targetName: $data[ 'target_name' ],
            error: $data[ 'error' ] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'target_name' => $this->targetName,
            'error' => $this->error,
        ];
    }
}
