<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use Superwire\Laravel\Tools\AbstractTool;

final readonly class ScopedTool
{
    public function __construct(
        public BoundToolDefinition $binding,
        public AbstractTool $tool,
    )
    {
    }
}
