<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class AgentLoopStartedEvent
{
    /**
     * @param list<array{iteration_index:int, bindings:array<string,mixed>}> $iterations
     */
    public function __construct(
        public int $iterationCount,
        public array $iterations,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            iterationCount: $data[ 'iteration_count' ] ?? count($data[ 'iterations' ] ?? []),
            iterations: $data[ 'iterations' ] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'iteration_count' => $this->iterationCount,
            'iterations' => $this->iterations,
        ];
    }
}
