<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class AgentStartedEvent
{
    /**
     * @param list<string> $tools
     */
    public function __construct(
        public string $model,
        public array $tools,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            model: $data[ 'model' ],
            tools: $data[ 'tools' ] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'tools' => $this->tools,
        ];
    }
}
