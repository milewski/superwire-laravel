<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Feature;

use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Superwire\Laravel\Tests\TestCase;
use Superwire\Laravel\Tools\AbstractTool;
use Superwire\Laravel\Tools\AgentToolset;
use Superwire\Laravel\Tools\WorkflowBoundInput;
use Superwire\Laravel\Tools\WorkflowTool;
use Superwire\Laravel\Tools\WorkflowToolInput;
use Superwire\Laravel\Workflow;

final class ToolAbstractionsTest extends TestCase
{
    public function test_abstract_tool_executes_handle_with_resolved_inputs(): void
    {
        $tool = new ToolAbstractionsTestTool();

        $result = $tool->execute(
            agentInput: new ToolAbstractionsTestInput(topic: 'launch'),
            boundInput: new ToolAbstractionsTestBoundInput(prefix: 'ship'),
        );

        $this->assertSame([ 'message' => 'ship launch' ], $result);
    }

    public function test_abstract_tool_requires_handle_method(): void
    {
        $tool = new class () extends AbstractTool {
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Tool `%s` must define `handle()`.', $tool::class));

        $tool->execute();
    }

    public function test_abstract_tool_requires_array_result(): void
    {
        $tool = new class () extends AbstractTool {
            protected function handle(): string
            {
                return 'not-an-array';
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Tool `%s` must return an array from handle().', $tool::class));

        $tool->execute();
    }

    public function test_agent_toolset_rejects_non_workflow_tools(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Configured tool `%s` must implement %s.',
            ToolAbstractionsInvalidTool::class,
            WorkflowTool::class,
        ));

        AgentToolset::fromArray([ ToolAbstractionsInvalidTool::class ], [ 'type' => 'object' ]);
    }

    public function test_abstract_tool_returns_tool_error_for_missing_arguments_before_handle(): void
    {
        ToolValidationRetryWeatherTool::reset();

        $toolDefinition = Workflow::fromFile(__DIR__ . '/../stubs/tool_schema_retry.wire')
            ->definition()
            ->toolDefinitionNamed(ToolValidationRetryWeatherTool::name());

        $this->assertNotNull($toolDefinition);

        $result = (new ToolValidationRetryWeatherTool())
            ->toAiToolFromDefinition($toolDefinition)
            ->handle(new Request([ 'country' => 'portugal' ]));

        $this->assertStringContainsString('city', (string) $result);
        $this->assertSame(0, ToolValidationRetryWeatherTool::handleCallCount());
    }

    public function test_abstract_tool_returns_tool_error_for_invalid_argument_type_before_handle(): void
    {
        ToolValidationRetryWeatherTool::reset();

        $toolDefinition = Workflow::fromFile(__DIR__ . '/../stubs/tool_schema_retry.wire')
            ->definition()
            ->toolDefinitionNamed(ToolValidationRetryWeatherTool::name());

        $this->assertNotNull($toolDefinition);

        $result = (new ToolValidationRetryWeatherTool())
            ->toAiToolFromDefinition($toolDefinition)
            ->handle(new Request([ 'city' => 123 ]));

        $this->assertStringContainsString('tool `retry_weather_tool` input is invalid', (string) $result);
        $this->assertSame(0, ToolValidationRetryWeatherTool::handleCallCount());
    }
}

final class ToolAbstractionsTestTool extends AbstractTool
{
    protected function handle(ToolAbstractionsTestInput $agentInput, ToolAbstractionsTestBoundInput $boundInput): array
    {
        return [ 'message' => $boundInput->prefix . ' ' . $agentInput->topic ];
    }
}

final class ToolAbstractionsTestInput extends WorkflowToolInput
{
    public function __construct(
        public string $topic,
    )
    {
    }
}

final class ToolAbstractionsTestBoundInput extends WorkflowBoundInput
{
    public function __construct(
        public string $prefix,
    )
    {
    }
}

final class ToolAbstractionsInvalidTool
{
}

final class ToolValidationRetryWeatherTool extends AbstractTool
{
    private static int $handleCallCount = 0;

    public static function name(): string
    {
        return 'retry_weather_tool';
    }

    public static function reset(): void
    {
        self::$handleCallCount = 0;
    }

    public static function handleCallCount(): int
    {
        return self::$handleCallCount;
    }

    protected function handle(ToolValidationRetryWeatherInput $agentInput): array
    {
        self::$handleCallCount++;

        return [ 'weather' => sprintf('sunny in %s', $agentInput->city) ];
    }
}

final class ToolValidationRetryWeatherInput extends WorkflowToolInput
{
    public function __construct(
        public string $city,
    )
    {
    }
}
