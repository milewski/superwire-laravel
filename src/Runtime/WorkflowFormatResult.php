<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

final class WorkflowFormatResult
{
    public function __construct(
        public string $formattedSource,
    )
    {
    }
}
