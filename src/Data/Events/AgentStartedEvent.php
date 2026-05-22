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
        public ?int $iterationIndex = null,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            model: $data[ 'model' ],
            tools: $data[ 'tools' ] ?? [],
            iterationIndex: $data[ 'iteration_index' ] ?? null,
        );
    }

    public function toArray(): array
    {
        $data = [
            'model' => $this->model,
            'tools' => $this->tools,
        ];

        if ($this->iterationIndex !== null) {
            $data[ 'iteration_index' ] = $this->iterationIndex;
        }

        return $data;
    }
}
