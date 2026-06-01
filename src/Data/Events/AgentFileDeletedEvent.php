<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class AgentFileDeletedEvent
{
    public function __construct(
        public string $fileId,
        public string $filename,
        public string $purpose,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            fileId: $data[ 'file_id' ],
            filename: $data[ 'filename' ],
            purpose: $data[ 'purpose' ],
        );
    }

    public function toArray(): array
    {
        return [
            'file_id' => $this->fileId,
            'filename' => $this->filename,
            'purpose' => $this->purpose,
        ];
    }
}
