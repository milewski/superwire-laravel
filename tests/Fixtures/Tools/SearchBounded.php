<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Fixtures\Tools;

use Superwire\Laravel\Contracts\BoundInput;

final readonly class SearchBounded implements BoundInput
{
    public function __construct(
        public string $tenantId,
    )
    {
    }

    public static function fromArray(array $values): self
    {
        return new self(tenantId: $values[ 'tenant_id' ]);
    }
}
