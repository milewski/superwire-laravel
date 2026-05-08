<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

final class WorkflowValidationResult
{
    public function __construct(
        public array $context,
    )
    {
    }
}
