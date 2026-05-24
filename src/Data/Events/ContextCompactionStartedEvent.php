<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class ContextCompactionStartedEvent
{
    public function __construct(
        public string $model,
        public ?string $sourceAgentName = null,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            model: $data[ 'model' ],
            sourceAgentName: $data[ 'source_agent_name' ] ?? null,
        );
    }

    public function toArray(): array
    {
        $data = [
            'model' => $this->model,
        ];

        if ($this->sourceAgentName !== null) {
            $data[ 'source_agent_name' ] = $this->sourceAgentName;
        }

        return $data;
    }
}
