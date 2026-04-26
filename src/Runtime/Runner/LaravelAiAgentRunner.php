<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner;

use Illuminate\Contracts\Config\Repository;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Superwire\Laravel\Contracts\AgentRunner;
use Superwire\Laravel\Contracts\StreamableAgentRunner;
use Superwire\Laravel\Data\Agent\OutputField;
use Superwire\Laravel\Enums\OutputStrategy;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\AgentRunResult;
use Superwire\Laravel\Runtime\Runner\Output\OutputSchemaTypeMapper;
use Superwire\Laravel\Runtime\Runner\Strategy\StructuredOutputStrategy;
use Superwire\Laravel\Runtime\Runner\Strategy\ToolCallingStrategy;
use Superwire\Laravel\Runtime\Tool\BoundToolDefinition;
use Superwire\Laravel\Runtime\Tool\LaravelAiTool;

final readonly class LaravelAiAgentRunner implements AgentRunner, StreamableAgentRunner
{
    public function __construct(
        private AiManager $ai,
        private Repository $config,
        private OutputSchemaTypeMapper $schemaTypeMapper = new OutputSchemaTypeMapper(),
    )
    {
    }

    public function run(AgentInvocation $invocation): AgentRunResult
    {
        $this->configureProvider(invocation: $invocation);

        $provider = $this->ai->textProvider(name: $invocation->provider->name);
        $response = $provider->prompt($this->prompt(invocation: $invocation, provider: $provider));
        $output = $this->strategy(invocation: $invocation)->output(response: $response);

        return new AgentRunResult(
            output: $output,
            history: $this->responseHistory(invocation: $invocation, response: $response, output: $output),
        );
    }

    public function runStream(AgentInvocation $invocation): StreamableAgentResponse
    {
        $this->configureProvider(invocation: $invocation);

        $provider = $this->ai->textProvider(name: $invocation->provider->name);

        return $provider->stream($this->prompt(invocation: $invocation, provider: $provider));
    }

    private function prompt(AgentInvocation $invocation, TextProvider $provider): AgentPrompt
    {
        return new AgentPrompt(
            agent: $this->agentForOutput(field: $this->outputField(invocation: $invocation), invocation: $invocation),
            prompt: $invocation->prompt,
            attachments: [],
            provider: $provider,
            model: $invocation->model,
        );
    }

    private function agentForOutput(OutputField $field, AgentInvocation $invocation): AnonymousAgent
    {
        return $this->strategy(invocation: $invocation)->agent(
            field: $field,
            invocation: $invocation,
            tools: $this->tools(invocation: $invocation),
            schemaTypeMapper: $this->schemaTypeMapper,
        );
    }

    private function strategy(AgentInvocation $invocation): StructuredOutputStrategy|ToolCallingStrategy
    {
        return match ($invocation->outputStrategy) {
            OutputStrategy::Structured => new StructuredOutputStrategy(),
            OutputStrategy::ToolCalling => new ToolCallingStrategy(),
        };
    }

    private function tools(AgentInvocation $invocation): array
    {
        return array_map(
            callback: fn (BoundToolDefinition $tool) => new LaravelAiTool($tool),
            array: $invocation->tools,
        );
    }

    private function responseHistory(AgentInvocation $invocation, mixed $response, array|string $output): array
    {
        $history = [ [
            'role' => 'user',
            'content' => $invocation->prompt,
        ] ];

        foreach ($response->steps as $step) {

            $history[] = [
                'role' => 'assistant',
                'content' => $step->text,
                'tool_calls' => $this->arrayValues($step->toolCalls),
                'tool_results' => $this->arrayValues($step->toolResults),
                'finish_reason' => $step->finishReason->value,
                'usage' => $this->arrayValue($step->usage),
                'meta' => $this->arrayValue($step->meta),
            ];

        }

        if ($history === [ [ 'role' => 'user', 'content' => $invocation->prompt ] ]) {

            foreach ($response->messages as $message) {
                $history[] = $this->messageHistoryEntry(message: $message);
            }

        }

        if (count($history) === 1) {

            $history[] = [
                'role' => 'assistant',
                'content' => is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_SLASHES),
                'tool_calls' => $this->arrayValues($response->toolCalls),
                'tool_results' => $this->arrayValues($response->toolResults),
                'usage' => $this->arrayValue($response->usage),
                'meta' => $this->arrayValue($response->meta),
            ];

        }

        return $history;
    }

    private function messageHistoryEntry(mixed $message): array
    {
        $entry = [
            'role' => $message->role->value,
            'content' => $message->content,
        ];

        if (property_exists($message, 'toolCalls')) {
            $entry[ 'tool_calls' ] = $this->arrayValues($message->toolCalls);
        }

        if (property_exists($message, 'toolResults')) {
            $entry[ 'tool_results' ] = $this->arrayValues($message->toolResults);
        }

        return $entry;
    }

    private function arrayValues(iterable $values): array
    {
        $items = [];

        foreach ($values as $value) {
            $items[] = $this->arrayValue($value);
        }

        return $items;
    }

    private function arrayValue(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value;
    }

    private function outputField(AgentInvocation $invocation): OutputField
    {
        return $invocation->agent->runsForEach()
            ? $invocation->agent->output->iteration
            : $invocation->agent->output->finalOutput;
    }

    private function configureProvider(AgentInvocation $invocation): void
    {
        $this->config->set(
            key: 'ai.providers.' . $invocation->provider->name,
            value: $this->providerConfig(invocation: $invocation),
        );

        $this->ai->purge(name: $invocation->provider->name);
    }

    private function providerConfig(AgentInvocation $invocation): array
    {
        $config = $invocation->providerConfig;

        if (array_key_exists('api_key', $config) && !array_key_exists('key', $config)) {
            $config[ 'key' ] = $config[ 'api_key' ];
        }

        if (array_key_exists('endpoint', $config) && !array_key_exists('url', $config)) {
            $config[ 'url' ] = $config[ 'endpoint' ];
        }

        unset($config[ 'api_key' ], $config[ 'endpoint' ]);

        return $config;
    }
}
