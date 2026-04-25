<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Fixtures;

use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Runtime\AgentInvocation;

final class FakeAgentRunner implements AgentRunner
{
    private array $invocations = [];

    public function __construct(
        private readonly array $responses,
    )
    {
    }

    public static function fake(array $responses): self
    {
        $runner = new self(responses: $responses);

        app()->instance(AgentRunner::class, $runner);

        return $runner;
    }

    public function run(AgentInvocation $invocation): array | string
    {
        $this->invocations[] = $invocation;

        $response = $this->responses[ $invocation->agent->name ] ?? $invocation->agent->name;

        if (is_callable($response)) {
            return $response($invocation);
        }

        return $response;
    }

    public function invocation(int $index): AgentInvocation
    {
        return $this->invocations[ $index ];
    }

    public function agentNames(): array
    {
        return array_map(
            callback: fn (AgentInvocation $invocation): string => $invocation->agent->name,
            array: $this->invocations,
        );
    }
}
