<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Fixtures;

final readonly class WorkflowMappedOutput
{
    public function __construct(
        public string $review,
    )
    {
    }
}
