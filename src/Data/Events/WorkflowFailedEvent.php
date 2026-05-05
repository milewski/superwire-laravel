<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class WorkflowFailedEvent
{
    public function __construct(
        public string $message,
    )
    {
    }

    public static function fromArray(string $message): self
    {
        return new self(message: $message);
    }

    public function toArray(): array
    {
        return [
            'message' => $this->message,
        ];
    }
}
