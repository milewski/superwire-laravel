<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Contracts;

use Superwire\Laravel\Runtime\AgentInvocation;

interface AgentRunner
{
    public function run(AgentInvocation $invocation): mixed;
}
