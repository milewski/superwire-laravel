<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner;

use Illuminate\Contracts\Config\Repository;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Runtime\AgentInvocation;

final readonly class LaravelAiAgentRunner implements AgentRunner
{
    public function __construct(
        private AiManager $ai,
        private Repository $config,
    )
    {
    }

    public function run(AgentInvocation $invocation): array | string | object
    {
        $this->configureProvider(invocation: $invocation);

        $provider = $this->ai->textProvider(name: $invocation->provider->name);
        $response = $provider->prompt(new AgentPrompt(
            agent: new AnonymousAgent(
                instructions: '',
                messages: [],
                tools: [],
            ),
            prompt: $invocation->prompt,
            attachments: [],
            provider: $provider,
            model: $invocation->model,
        ));

        if ($response instanceof StructuredAgentResponse) {
            return $response->structured;
        }

        return $response->text;
    }

    private function configureProvider(AgentInvocation $invocation): void
    {
        $this->config->set(
            key: 'ai.providers.' . $invocation->provider->name,
            value: $invocation->providerConfig,
        );

        $this->ai->purge(name: $invocation->provider->name);
    }
}
