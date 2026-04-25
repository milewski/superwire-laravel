<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Data\Agent\Provider;

final readonly class AgentInvocation
{
    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $secrets
     * @param array<string, mixed> $agentOutputs
     * @param array<string, mixed> $providerConfig
     */
    public function __construct(
        public Agent $agent,
        public Provider $provider,
        public mixed $model,
        public string $prompt,
        public array $providerConfig,
        public array $inputs,
        public array $secrets,
        public array $agentOutputs,
        public ?string $iterationIdentifier = null,
        public mixed $iterationValue = null,
    )
    {
    }
}
