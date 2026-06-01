<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Events;

final readonly class AgentFileCreatedEvent
{
    public function __construct(
        public string $fileId,
        public string $filename,
        public string $purpose,
        public ?int $bytes = null,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            fileId: $data[ 'file_id' ],
            filename: $data[ 'filename' ],
            purpose: $data[ 'purpose' ],
            bytes: $data[ 'bytes' ] ?? null,
        );
    }

    public function toArray(): array
    {
        $data = [
            'file_id' => $this->fileId,
            'filename' => $this->filename,
            'purpose' => $this->purpose,
        ];

        if ($this->bytes !== null) {
            $data[ 'bytes' ] = $this->bytes;
        }

        return $data;
    }
}
