<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime;

use InvalidArgumentException;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Data\Agent\Agent;
use Superwire\Laravel\Runtime\OutputParser;
use Superwire\Laravel\Tests\TestCase;

final class OutputParserTest extends TestCase
{
    public function test_it_keeps_string_outputs_as_trimmed_strings(): void
    {
        $this->assertSame(
            expected: 'hello',
            actual: $this->parse(output: ' hello ', outputType: 'string'),
        );
    }

    public function test_it_parses_integer_outputs(): void
    {
        $this->assertSame(
            expected: 42,
            actual: $this->parse(output: '42', outputType: 'number'),
        );
    }

    public function test_it_parses_float_outputs(): void
    {
        $this->assertSame(
            expected: 42.5,
            actual: $this->parse(output: '42.5', outputType: 'float'),
        );
    }

    public function test_it_parses_boolean_outputs(): void
    {
        $this->assertTrue(
            condition: $this->parse(output: 'true', outputType: 'boolean'),
        );
    }

    public function test_it_parses_array_outputs_from_json(): void
    {
        $this->assertSame(
            expected: [ 'one', 'two' ],
            actual: $this->parse(output: '["one", "two"]', outputType: '[string]'),
        );
    }

    public function test_it_parses_object_outputs_from_json(): void
    {
        $this->assertSame(
            expected: [ 'message' => 'hello' ],
            actual: $this->parse(output: '{ "message": "hello" }', outputType: "{ message: string }"),
        );
    }

    public function test_it_parses_all_supported_complex_dsl_types(): void
    {
        $agent = $this->agentFromFixture(fixture: 'all_types_output.wire', agentName: 'all_types');
        $output = [
            'string_value' => 'hello',
            'number_value' => '42',
            'float_value' => '42.5',
            'boolean_value' => 'true',
            'explicit_null' => 'null',
            'nullable_string' => null,
            'nullable_number' => '123',
            'array' => [ 'one', 'two' ],
            'fixed_array' => [ 'one', 'two', 'three' ],
            'array_of_objects' => [
                [ 'id' => 'first', 'score' => '10' ],
                [ 'id' => 'second', 'score' => 20 ],
            ],
            'enum_value' => 'ready',
            'nullable_enum' => null,
            'tuple_value' => [ 'value', '7', [ 'one', 'two', 'three' ] ],
            'nullable_tuple' => null,
            'object_value' => [
                'string_value' => 'nested',
                'number_value' => '9',
            ],
            'nullable_object' => null,
        ];

        $this->assertEquals(
            expected: [
                'array' => [ 'one', 'two' ],
                'array_of_objects' => [
                    [ 'id' => 'first', 'score' => 10 ],
                    [ 'id' => 'second', 'score' => 20 ],
                ],
                'boolean_value' => true,
                'enum_value' => 'ready',
                'explicit_null' => null,
                'fixed_array' => [ 'one', 'two', 'three' ],
                'float_value' => 42.5,
                'nullable_enum' => null,
                'nullable_number' => 123,
                'nullable_object' => null,
                'nullable_string' => null,
                'nullable_tuple' => null,
                'number_value' => 42,
                'object_value' => [
                    'number_value' => 9,
                    'string_value' => 'nested',
                ],
                'string_value' => 'hello',
                'tuple_value' => [ 'value', 7, [ 'one', 'two', 'three' ] ],
            ],
            actual: new OutputParser()->parse(
                output: $output,
                field: $agent->output->finalOutput,
                agent: $agent,
            ),
        );
    }

    public function test_it_rejects_invalid_enum_values(): void
    {
        $agent = $this->agentFromFixture(fixture: 'all_types_output.wire', agentName: 'all_types');
        $output = json_decode($this->validAllTypesJson(), true, flags: JSON_THROW_ON_ERROR);
        $output[ 'enum_value' ] = 'archived';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent `all_types` returned output that is not an allowed enum value.');

        new OutputParser()->parse(
            output: $output,
            field: $agent->output->finalOutput,
            agent: $agent,
        );
    }

    public function test_it_rejects_fixed_arrays_with_the_wrong_length(): void
    {
        $agent = $this->agentFromFixture(fixture: 'all_types_output.wire', agentName: 'all_types');
        $output = json_decode($this->validAllTypesJson(), true, flags: JSON_THROW_ON_ERROR);
        $output[ 'fixed_array' ] = [ 'one', 'two' ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent `all_types` returned output that does not match the fixed array length.');

        new OutputParser()->parse(
            output: $output,
            field: $agent->output->finalOutput,
            agent: $agent,
        );
    }

    public function test_it_rejects_tuples_with_the_wrong_length(): void
    {
        $agent = $this->agentFromFixture(fixture: 'all_types_output.wire', agentName: 'all_types');
        $output = json_decode($this->validAllTypesJson(), true, flags: JSON_THROW_ON_ERROR);
        $output[ 'tuple_value' ] = [ 'value', 7 ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent `all_types` returned output that cannot be parsed as a tuple.');

        new OutputParser()->parse(
            output: $output,
            field: $agent->output->finalOutput,
            agent: $agent,
        );
    }

    public function test_it_returns_structured_arrays_without_casting(): void
    {
        $this->assertSame(
            expected: [ 'message' => 'hello' ],
            actual: $this->parse(output: [ 'message' => 'hello' ], outputType: "{ message: string }"),
        );
    }

    public function test_it_rejects_unparseable_outputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent `greeting` returned output that cannot be parsed as an integer.');

        $this->parse(output: 'not a number', outputType: 'number');
    }

    private function parse(string | array $output, string $outputType): array | string | int | float | bool | null
    {
        $agent = $this->agent(outputType: $outputType);

        return new OutputParser()->parse(
            output: $output,
            field: $agent->output->finalOutput,
            agent: $agent,
        );
    }

    private function agent(string $outputType): Agent
    {
        $workflowPath = $this->writeTemporaryWorkflow(
            wire: $this->wireWithOutput(output: $outputType),
            prefix: 'superwire-output-parser-',
        );

        try {
            $definition = $this->app->make(WorkflowCompiler::class)->compile(workflowPath: $workflowPath);
        } finally {
            unlink(filename: $workflowPath);
        }

        return $definition->agents->findByName(name: 'greeting');
    }

    private function agentFromFixture(string $fixture, string $agentName): Agent
    {
        $definition = $this->app->make(WorkflowCompiler::class)->compile(
            workflowPath: __DIR__ . '/../Stubs/' . $fixture,
        );

        return $definition->agents->findByName(name: $agentName);
    }

    private function validAllTypesJson(): string
    {
        return <<<'JSON'
        {
            "string_value": "hello",
            "number_value": 42,
            "float_value": 42.5,
            "boolean_value": true,
            "explicit_null": null,
            "nullable_string": null,
            "nullable_number": 123,
            "array": ["one", "two"],
            "fixed_array": ["one", "two", "three"],
            "array_of_objects": [
                {"id": "first", "score": 10}
            ],
            "enum_value": "ready",
            "nullable_enum": null,
            "tuple_value": ["value", 7, ["one", "two", "three"]],
            "nullable_tuple": null,
            "object_value": {
                "string_value": "nested",
                "number_value": 9
            },
            "nullable_object": null
        }
        JSON;
    }

    private function wireWithOutput(string $output): string
    {
        return <<<WIRE
            provider openai {
                driver: "openai"
                endpoint: "http://example.test/v1"
                api_key: "test-key"
                models: ["test-model"]
            }
            
            agent greeting {
                model: openai("test-model")
                prompt: "Write a short welcome message."
                output: $output
            }
            
            output {
                greeting: agent.greeting
            }
        WIRE;
    }
}
