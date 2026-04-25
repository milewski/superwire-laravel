<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Fixtures;

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Superwire\Laravel\Contracts\StreamableAgentRunner;
use Superwire\Laravel\Runtime\AgentInvocation;

final class FakeStreamableAgentRunner extends FakeAgentRunner implements StreamableAgentRunner
{
    public ?AgentInvocation $streamInvocation = null;

    public function __construct(array $responses, private readonly string $streamText)
    {
        parent::__construct(responses: $responses);
    }

    public function runStream(AgentInvocation $invocation): StreamableAgentResponse
    {
        $this->streamInvocation = $invocation;

        return new StreamableAgentResponse(
            invocationId: 'invocation-1',
            generator: fn (): array => [
                new TextDelta(
                    id: 'event-1',
                    messageId: 'message-1',
                    delta: $this->streamText,
                    timestamp: 1,
                ),
            ],
            meta: new Meta(),
        );
    }
}
