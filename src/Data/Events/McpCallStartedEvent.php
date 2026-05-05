<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpCallStartedEvent
{
    public function __construct(
        public string $operation,
        public string $targetName,
        public mixed $arguments,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            operation: $data[ 'operation' ],
            targetName: $data[ 'target_name' ],
            arguments: $data[ 'arguments' ] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'target_name' => $this->targetName,
            'arguments' => $this->arguments,
        ];
    }
}
