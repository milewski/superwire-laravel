<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Data\Agent\Provider;

final class AgentInvocation
{
    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $secrets
     * @param array<string, mixed> $agentOutputs
     * @param array<string, mixed> $providerConfig
     */
    public function __construct(
        public readonly Agent $agent,
        public readonly Provider $provider,
        public readonly mixed $model,
        public readonly string $prompt,
        public readonly array $providerConfig,
        public readonly array $inputs,
        public readonly array $secrets,
        public readonly array $agentOutputs,
        public readonly ?string $iterationIdentifier = null,
        public readonly mixed $iterationValue = null,
    ) {
    }
}
