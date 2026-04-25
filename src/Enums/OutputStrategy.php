<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Enums;

enum OutputStrategy: string
{
    case Structured = 'structured';
    case ToolCalling = 'tool_calling';
}
