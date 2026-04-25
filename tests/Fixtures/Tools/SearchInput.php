<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Fixtures\Tools;

final readonly class SearchInput
{
    public function __construct(
        public string $query,
    )
    {
    }
}
