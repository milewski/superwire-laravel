<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use LogicException;
use Superwire\Laravel\Contracts\AgentRunner;

final class MissingAgentRunner implements AgentRunner
{
    public function run(AgentInvocation $invocation): array | string | object
    {
        throw new LogicException('No Superwire agent runner is configured. Bind ' . AgentRunner::class . ' to execute workflow agents.');
    }
}
