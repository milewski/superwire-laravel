<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use Superwire\Laravel\Data\Events\AgentCompletedEvent;
use Superwire\Laravel\Data\Events\AgentStartedEvent;
use Superwire\Laravel\Data\Events\McpCallCompletedEvent;
use Superwire\Laravel\Data\Events\McpCallFailedEvent;
use Superwire\Laravel\Data\Events\McpCallStartedEvent;
use Superwire\Laravel\Data\Events\ToolCallCompletedEvent;
use Superwire\Laravel\Data\Events\ToolCallFailedEvent;
use Superwire\Laravel\Data\Events\ToolCallStartedEvent;
use Superwire\Laravel\Data\Events\WorkflowCompletedEvent;
use Superwire\Laravel\Data\Events\WorkflowFailedEvent;
use Superwire\Laravel\Data\Events\WorkflowPlannedEvent;
use Superwire\Laravel\Data\Events\WorkflowStartedEvent;
use Superwire\Laravel\Enums\ExecutorEventKind;

final readonly class ExecutorEvent
{
    /**
     * @param AgentCompletedEvent|AgentStartedEvent|McpCallCompletedEvent|McpCallFailedEvent|McpCallStartedEvent|ToolCallCompletedEvent|ToolCallFailedEvent|ToolCallStartedEvent|WorkflowCompletedEvent|WorkflowFailedEvent|WorkflowPlannedEvent|WorkflowStartedEvent $event
     */
    public function __construct(
        public ExecutorEventKind $kind,
        public ?string $agentName,
        public object $event,
    )
    {
    }

    public static function fromArray(array $payload): self
    {
        $kind = ExecutorEventKind::from($payload[ 'kind' ]);
        $data = $payload[ 'data' ] ?? [];
        $message = $payload[ 'message' ] ?? null;
        $agentName = $payload[ 'agent_name' ] ?? null;

        $event = match ($kind) {
            ExecutorEventKind::WorkflowStarted => new WorkflowStartedEvent(),
            ExecutorEventKind::WorkflowPlanned => WorkflowPlannedEvent::fromArray($data),
            ExecutorEventKind::AgentStarted => AgentStartedEvent::fromArray($data),
            ExecutorEventKind::AgentCompleted => AgentCompletedEvent::fromArray($data),
            ExecutorEventKind::ToolCallStarted => ToolCallStartedEvent::fromArray($data),
            ExecutorEventKind::ToolCallCompleted => ToolCallCompletedEvent::fromArray($data),
            ExecutorEventKind::ToolCallFailed => ToolCallFailedEvent::fromArray($data),
            ExecutorEventKind::McpCallStarted => McpCallStartedEvent::fromArray($data),
            ExecutorEventKind::McpCallCompleted => McpCallCompletedEvent::fromArray($data),
            ExecutorEventKind::McpCallFailed => McpCallFailedEvent::fromArray($data),
            ExecutorEventKind::WorkflowCompleted => WorkflowCompletedEvent::fromArray($data),
            ExecutorEventKind::WorkflowFailed => WorkflowFailedEvent::fromArray($message ?? 'Unknown error'),
        };

        return new self(
            kind: $kind,
            agentName: $agentName,
            event: $event,
        );
    }

    public function isTerminal(): bool
    {
        return $this->kind->isTerminal();
    }

    public function output(): mixed
    {
        if ($this->event instanceof WorkflowCompletedEvent) {
            return $this->event->output;
        }

        if ($this->event instanceof AgentCompletedEvent) {
            return $this->event->output;
        }

        return null;
    }

    public function toArray(): array
    {
        $array = [
            'kind' => $this->kind->value,
        ];

        if ($this->agentName !== null) {
            $array[ 'agent_name' ] = $this->agentName;
        }

        $eventData = $this->event->toArray();

        if ($this->event instanceof WorkflowFailedEvent) {

            $array[ 'message' ] = $this->event->message;

        } elseif ($eventData !== []) {

            $array[ 'data' ] = $eventData;

        }

        return $array;
    }
}
