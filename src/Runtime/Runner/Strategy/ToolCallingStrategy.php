<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner\Strategy;

use InvalidArgumentException;
use Laravel\Ai\AnonymousAgent;
use Superwire\Laravel\Data\Agent\OutputField;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\Runner\Agent\SuperwireAnonymousAgent;
use Superwire\Laravel\Runtime\Runner\Output\OutputAbortTool;
use Superwire\Laravel\Runtime\Runner\Output\OutputSchemaTypeMapper;
use Superwire\Laravel\Runtime\Runner\Output\OutputSuccessTool;

final readonly class ToolCallingStrategy
{
    public function agent(OutputField $field, AgentInvocation $invocation, array $tools, OutputSchemaTypeMapper $schemaTypeMapper): AnonymousAgent
    {
        return new SuperwireAnonymousAgent(
            instructions: '',
            messages: [],
            tools: [
                ...$tools,
                new OutputSuccessTool(field: $field, schemaTypeMapper: $schemaTypeMapper),
                new OutputAbortTool(),
            ],
        );
    }

    public function output(mixed $response): array | string
    {
        foreach (array_reverse($response->toolResults->all()) as $toolResult) {
            if (!in_array($toolResult->name, [ 'OutputSuccessTool', 'OutputAbortTool' ], true)) {
                continue;
            }

            $result = is_string($toolResult->result) ? json_decode($toolResult->result, true) : $toolResult->result;

            if (!is_array($result)) {
                continue;
            }

            if (($result[ 'superwire_output_abort' ] ?? false) === true) {
                throw new InvalidArgumentException('Agent aborted output: ' . ($result[ 'reason' ] ?? 'No reason provided.'));
            }

            if (($result[ 'superwire_output_success' ] ?? false) === true) {
                $output = $result[ 'output' ] ?? [];

                return is_array($output) ? $output : (string) $output;
            }
        }

        throw new InvalidArgumentException('Agent did not call the required output tool.');
    }
}
