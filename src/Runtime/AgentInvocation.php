<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Data\Agent\Provider;

final readonly class AgentInvocation
{
    public function __construct(
        public Agent $agent,
        public Provider $provider,
        public string $model,
        public string $prompt,
        public array $providerConfig,
        public array $inputs,
        public array $secrets,
        public array $agentOutputs,
        public array $tools,
        public ?string $iterationIdentifier = null,
        public mixed $iterationValue = null,
    )
    {
    }
}
