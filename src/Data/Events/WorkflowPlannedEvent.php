<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class PlannedMcpImport
{
    public function __construct(
        public string $name,
        public string $kind,
        public string $serverName,
        public string $itemName,
    )
    {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            name: $payload[ 'name' ],
            kind: $payload[ 'kind' ],
            serverName: $payload[ 'server_name' ],
            itemName: $payload[ 'item_name' ],
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind,
            'server_name' => $this->serverName,
            'item_name' => $this->itemName,
        ];
    }
}

final readonly class WorkflowPlannedEvent
{
    /**
     * @param list<string> $agentExecutionOrder
     * @param list<PlannedMcpImport> $mcpImports
     */
    public function __construct(
        public array $agentExecutionOrder,
        public array $mcpImports,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            agentExecutionOrder: $data[ 'agent_execution_order' ] ?? [],
            mcpImports: array_map(
                static fn (array $import): PlannedMcpImport => PlannedMcpImport::fromArray($import),
                $data[ 'mcp_imports' ] ?? [],
            ),
        );
    }

    public function toArray(): array
    {
        return [
            'agent_execution_order' => $this->agentExecutionOrder,
            'mcp_imports' => array_map(
                static fn (PlannedMcpImport $import): array => $import->toArray(),
                $this->mcpImports,
            ),
        ];
    }
}
