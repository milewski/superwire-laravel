<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class McpToolSchemaFetchStartedEvent
{
    public function __construct(
        public string $serverName,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            serverName: $data[ 'server_name' ],
        );
    }

    public function toArray(): array
    {
        return [
            'server_name' => $this->serverName,
        ];
    }
}
