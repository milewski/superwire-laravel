<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Contracts;

use Laravel\Ai\Responses\StreamableAgentResponse;
use Superwire\Laravel\Runtime\AgentInvocation;

interface StreamableAgentRunner
{
    public function runStream(AgentInvocation $invocation): StreamableAgentResponse;
}
