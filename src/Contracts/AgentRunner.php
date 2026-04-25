<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Contracts;

use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\AgentRunResult;

interface AgentRunner
{
    public function run(AgentInvocation $invocation): AgentRunResult;
}
