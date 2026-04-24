<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Concerns;

use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Superwire\Laravel\AgentExecutionResult;
use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Tools\AgentToolset;

trait ExecutesWorkflowAgents
{
    private function runAgent(Agent $agent, array $agentOutputs): AgentExecutionResult
    {
        /**
         * If the agent is not within a for each loop then it can be executed direcly,
         */
        if (!$agent->runsForEach()) {

            return $this->executeAgent(
                agent: $agent,
                prompt: $this->promptParser->render(
                    prompt: $agent->prompt,
                    agentOutputs: $agentOutputs,
                    inputValues: $this->inputValues,
                    secretValues: $this->secretValues,
                ),
                outputSchema: $agent->finalOutputJsonSchema(),
                agentOutputs: $agentOutputs,
            );

        }

        /**
         * When agent is within a for each loop first the dependencies has to be fetch to be provided for each interaction
         */
        $iterationValues = $this->resolveForEachValues($agent, $agentOutputs);
        $iterationIdentifier = $agent->forEachIdentifier();

        if ($iterationIdentifier === null) {
            throw new RuntimeException(sprintf('Agent %s is missing a for_each identifier.', $agent->name));
        }

        $results = [];

        /**
         * then a check is done to determine if this can actually be ran in parallel. for  example if only 1 values is present
         * then there is no point in running parallel
         */
        if ($this->shouldForkIterations($agent, $iterationValues)) {

            $results = $this->forkRunner()->run(...$this->iterationTasks(
                agent: $agent,
                agentOutputs: $agentOutputs,
                iterationIdentifier: $iterationIdentifier,
                iterationValues: $iterationValues,
            ));

            ksort($results);

            $iterationResults = array_map(
                callback: fn (mixed $result): AgentExecutionResult => $this->normalizeExecutionResult($result, sprintf('iteration agent %s', $agent->name)),
                array: array_values($results),
            );

            return new AgentExecutionResult(
                output: array_map(
                    callback: fn (AgentExecutionResult $iterationResult): mixed => $iterationResult->output,
                    array: $iterationResults,
                ),
                iterations: $iterationResults,
            );

        }

        foreach ($iterationValues as $iterationValue) {

            $prompt = $this->promptParser->render(
                prompt: $agent->prompt,
                agentOutputs: $agentOutputs,
                scope: [ $iterationIdentifier => $iterationValue ],
                inputValues: $this->inputValues,
                secretValues: $this->secretValues,
            );

            $results[] = $this->executeAgent(
                agent: $agent,
                prompt: $prompt,
                outputSchema: $agent->iterationJsonSchema(),
                agentOutputs: $agentOutputs,
                scope: [ $iterationIdentifier => $iterationValue ],
            );

        }

        return new AgentExecutionResult(
            output: array_map(
                callback: fn (AgentExecutionResult $iterationResult): mixed => $iterationResult->output,
                array: array_map(
                    fn (mixed $result): AgentExecutionResult => $this->normalizeExecutionResult($result, sprintf('iteration agent %s', $agent->name)),
                    $results,
                ),
            ),
            iterations: array_map(
                callback: fn (mixed $result): AgentExecutionResult => $this->normalizeExecutionResult($result, sprintf('iteration agent %s', $agent->name)),
                array: $results,
            ),
        );
    }

    private function executeAgent(Agent $agent, string $prompt, array $outputSchema, array $agentOutputs, array $scope = []): AgentExecutionResult
    {
        $toolset = AgentToolset::fromArray(
            tools: $this->resolveToolsForAgent($agent),
            outputSchema: $outputSchema,
            toolBindings: $this->resolveToolBindingsForAgent($agent, $agentOutputs, $scope),
            toolDefinitions: $this->definition->toolsByName(),
        );

        $toolset->resetFinalization();

        $response = $this->executeTextGeneration(
            agent: $agent,
            prompt: $prompt,
            instructions: $this->finalizationPrompt($outputSchema),
            tools: $toolset->aiTools(),
        );

        $finalizedExecutionResult = $toolset->finalizeExecutionResult(
            agentName: $agent->name,
            messages: $this->messagesToArray($response->messages->all()),
        );

        if ($finalizedExecutionResult !== null) {
            return $finalizedExecutionResult;
        }

        throw new RuntimeException(
            message: sprintf('Agent %s did not call finalize_success or finalize_error after %d tool steps.', $agent->name, $this->maxAgentToolSteps()),
        );
    }

    /**
     * @param array<int, \Laravel\Ai\Contracts\Tool> $tools
     */
    private function executeTextGeneration(Agent $agent, string $prompt, string $instructions, array $tools): TextResponse
    {
        $provider = $this->providerInstance($agent);
        $messages = [ new UserMessage($prompt) ];

        return $provider->textGateway()->generateText(
            provider: $provider,
            model: $this->resolveModel($agent),
            instructions: $instructions,
            messages: $messages,
            tools: $tools,
            schema: null,
            options: new TextGenerationOptions(
                maxSteps: $this->maxAgentToolSteps(),
                maxTokens: $agent->inference->maxTokens(),
                temperature: $agent->inference->temperature(),
            ),
            timeout: null,
        );
    }

    private function maxAgentToolSteps(): int
    {
        return (int) config('superwire.runtime.max_agent_tool_steps', 20);
    }

    /**
     * @param array<int, object> $messages
     * @return array<int, array<string, mixed>>
     */
    private function messagesToArray(array $messages): array
    {
        return array_map(
            callback: fn (object $message): array => $this->serializeMessage($message),
            array: $messages,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(object $message): array
    {
        return match (true) {
            $message instanceof UserMessage => $this->serializeUserMessage($message),
            $message instanceof AssistantMessage => $this->serializeAssistantMessage($message),
            $message instanceof ToolResultMessage => [
                'type' => 'tool_result',
                'tool_results' => $message->toolResults->map(function ($toolResult): array {
                    $result = $toolResult->toArray();
                    $result[ 'tool_name' ] = $result[ 'name' ] ?? null;

                    return $result;
                })->all(),
            ],
            default => method_exists($message, 'toArray') ? $message->toArray() : [ 'type' => 'unknown' ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUserMessage(UserMessage $message): array
    {
        $serializedMessage = [
            'type' => 'user',
            'content' => $message->content,
        ];

        if ($message->attachments->isNotEmpty()) {
            $serializedMessage[ 'attachments' ] = $message->attachments->all();
        }

        return $serializedMessage;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAssistantMessage(AssistantMessage $message): array
    {
        $serializedMessage = [
            'type' => 'assistant',
            'content' => $message->content,
            'tool_calls' => $message->toolCalls->map(fn ($toolCall): array => $toolCall->toArray())->all(),
        ];

        return $serializedMessage;
    }

    private function finalizationPrompt(array $outputSchema): string
    {
        return sprintf(
            <<<Prompt
            You must finish by calling exactly one tool: `finalize_success` or `finalize_error`.
            Call finalize_success when you have the final agent output.
            The finalize_success result argument must match this JSON schema exactly: %s.
            If you cannot complete the task, call finalize_error with a clear message.
            Do not end with plain text without calling one of these tools.
            Prompt,
            json_encode($outputSchema, JSON_THROW_ON_ERROR),
        );
    }
}
