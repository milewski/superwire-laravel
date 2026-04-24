<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tools;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use RuntimeException;
use Superwire\Laravel\AgentExecutionResult;
use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Tools\Internal\FinalizeErrorTool;
use Superwire\Laravel\Tools\Internal\FinalizeSuccessTool;

final readonly class AgentToolset
{
    /**
     * @param array<int, array{tool: WorkflowTool, tool_definition: ToolDefinition, bound_arguments: array<string, mixed>}> $userTools
     */
    private function __construct(
        private array $userTools,
        private FinalizeSuccessTool $finalizeSuccessTool,
        private FinalizeErrorTool $finalizeErrorTool,
    )
    {
    }

    /**
     * @param array<int, string|WorkflowTool> $tools
     * @param array<string, mixed> $outputSchema
     * @param array<string, array<string, mixed>> $toolBindings
     * @param array<string, ToolDefinition> $toolDefinitions
     */
    public static function fromArray(array $tools, array $outputSchema, array $toolBindings = [], array $toolDefinitions = []): self
    {
        $normalizedTools = [];

        foreach ($tools as $tool) {

            $workflowTool = self::normalizeTool($tool);
            $toolDefinition = $toolDefinitions[ $workflowTool::name() ] ?? null;

            if (!$toolDefinition instanceof ToolDefinition) {
                throw new RuntimeException(sprintf('Compiled workflow is missing a tool definition for `%s`.', $workflowTool::name()));
            }

            $normalizedTools[] = [
                'tool' => $workflowTool,
                'tool_definition' => $toolDefinition,
                'bound_arguments' => $toolBindings[ $workflowTool::name() ] ?? [],
            ];

        }

        return new self(
            userTools: $normalizedTools,
            finalizeSuccessTool: new FinalizeSuccessTool($outputSchema),
            finalizeErrorTool: new FinalizeErrorTool(),
        );
    }

    /**
     * @return array<int, Tool>
     */
    public function aiTools(): array
    {
        $tools = [];

        foreach ($this->userTools as $boundTool) {

            if ($boundTool[ 'tool' ] instanceof AbstractTool) {

                $tools[] = $boundTool[ 'tool' ]->toAiToolFromDefinition(
                    $boundTool[ 'tool_definition' ],
                    $boundTool[ 'bound_arguments' ],
                );

                continue;

            }

            $tools[] = $boundTool[ 'tool' ]->toAiTool($boundTool[ 'bound_arguments' ]);

        }

        $tools[] = $this->finalizeSuccessTool->toAiTool();
        $tools[] = $this->finalizeErrorTool->toAiTool();

        return $tools;
    }

    public function resetFinalization(): void
    {
        $this->finalizeSuccessTool->reset();
        $this->finalizeErrorTool->reset();
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public function finalizeExecutionResult(string $agentName, array $messages): ?AgentExecutionResult
    {
        foreach ($messages as $message) {
            foreach (($message[ 'tool_results' ] ?? []) as $toolResult) {
                $toolName = $toolResult[ 'name' ] ?? $toolResult[ 'tool_name' ] ?? null;

                if ($toolName === FinalizeSuccessTool::name()) {
                    return new AgentExecutionResult(
                        output: $toolResult[ 'arguments' ][ 'result' ] ?? null,
                        messages: $messages,
                    );
                }

                if ($toolName === FinalizeErrorTool::name()) {
                    throw new RuntimeException(
                        message: sprintf('Agent %s failed: %s', $agentName, $toolResult[ 'arguments' ][ 'message' ] ?? null),
                    );
                }
            }
        }

        if ($this->finalizeErrorTool->wasCalled()) {

            throw new RuntimeException(
                message: sprintf('Agent %s failed: %s', $agentName, $this->finalizeErrorTool->reason()),
            );

        }

        if (!$this->finalizeSuccessTool->wasCalled()) {
            return null;
        }

        return new AgentExecutionResult(
            output: $this->finalizeSuccessTool->result(),
            messages: $messages,
        );
    }

    private static function normalizeTool(WorkflowTool|string $tool): WorkflowTool
    {
        if (is_string($tool)) {
            $tool = app($tool);
        }

        if ($tool instanceof WorkflowTool) {
            return $tool;
        }

        throw new InvalidArgumentException(sprintf(
            'Configured tool `%s` must implement %s.',
            is_object($tool) ? $tool::class : gettype($tool),
            WorkflowTool::class,
        ));
    }
}
