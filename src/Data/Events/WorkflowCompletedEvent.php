<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class WorkflowCompletedEvent
{
    public function __construct(
        public mixed $output,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            output: $data[ 'output' ] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'output' => $this->output,
        ];
    }
}
