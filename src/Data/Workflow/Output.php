<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Data\Workflow;

use Superwire\Laravel\Data\Concerns\ValidatesPayload;

final class Output
{
    use ValidatesPayload;

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $contract
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $contract,
    )
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            fields: self::array($payload, 'fields'),
            contract: self::array($payload, 'contract'),
        );
    }
}
