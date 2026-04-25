<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Enums;

enum AgentMode: string
{
    case Request = 'request';
    case Stream = 'stream';
}
