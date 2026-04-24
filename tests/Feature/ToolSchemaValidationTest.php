<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Feature;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Superwire\Laravel\Support\JsonSchemaFactory;
use Superwire\Laravel\Testing\Fakes\TextRequest;
use Superwire\Laravel\Tests\Fakes\ScriptedToolLoopProvider;
use Superwire\Laravel\Tests\TestCase;
use Superwire\Laravel\Tools\AbstractTool;
use Superwire\Laravel\Tools\Internal\FinalizeSuccessTool;
use Superwire\Laravel\Tools\WorkflowBoundInput;
use Superwire\Laravel\Tools\WorkflowToolInput;
use Superwire\Laravel\Workflow;
use Swaggest\JsonSchema\Schema;

final class ToolSchemaValidationTest extends TestCase
{
    public function test_empty_object_tool_input_schema_can_be_imported_and_validated(): void
    {
        $schema = JsonSchemaFactory::fromArray([
            'additionalProperties' => false,
            'properties' => [],
            'required' => [],
            'type' => 'object',
        ], 'empty tool input schema');

        JsonSchemaFactory::validate($schema, [], 'empty tool input');

        $this->assertInstanceOf(Schema::class, $schema);
    }

    public function test_tool_registers_only_agent_input_schema_and_returns_failed_tool_result_for_invalid_arguments(): void
    {
        config()->set('superwire.runtime.stream', false);

        RetryWeatherTool::reset();

        $provider = $this->fakeRetryingToolProvider([ 'country' => 'portugal' ]);

        $result = Workflow::fromFile(__DIR__ . '/../stubs/tool_schema_retry.wire')
            ->withTools([ new BoundSchemaTool(), new RetryWeatherTool() ])
            ->run();

        $registeredTool = $provider->registeredTool(BoundSchemaTool::name());

        $this->assertNotNull($registeredTool);
        $this->assertSame([ 'city' ], $registeredTool->requiredParameters());
        $this->assertSame('string', $registeredTool->parametersAsArray()[ 'city' ][ 'type' ] ?? null);
        $this->assertArrayNotHasKey('tenant_id', $registeredTool->parametersAsArray());

        $this->assertTrue($provider->sawInvalidToolResultOnRetry());
        $this->assertStringContainsString('tool `retry_weather_tool` input is invalid', (string) $provider->invalidToolResultMessage());
        $this->assertStringContainsString('city', (string) $provider->invalidToolResultMessage());
        $this->assertSame('{"weather":"sunny in lisbon"}', $provider->validToolResult());
        $this->assertSame(1, RetryWeatherTool::handleCallCount());
        $this->assertSame([ 'weather' => 'sunny in lisbon' ], $result->output);
    }

    public function test_tool_returns_failed_tool_result_for_invalid_argument_types_without_calling_handle(): void
    {
        config()->set('superwire.runtime.stream', false);

        RetryWeatherTool::reset();

        $provider = $this->fakeRetryingToolProvider([ 'city' => 123 ]);

        $result = Workflow::fromFile(__DIR__ . '/../stubs/tool_schema_retry.wire')
            ->withTools([ new BoundSchemaTool(), new RetryWeatherTool() ])
            ->run();

        $this->assertTrue($provider->sawInvalidToolResultOnRetry());
        $this->assertNotNull($provider->invalidToolResultMessage());
        $this->assertStringContainsString('tool `retry_weather_tool` input is invalid', (string) $provider->invalidToolResultMessage());
        $this->assertStringContainsString('string', strtolower((string) $provider->invalidToolResultMessage()));
        $this->assertSame('{"weather":"sunny in lisbon"}', $provider->validToolResult());
        $this->assertSame(1, RetryWeatherTool::handleCallCount());
        $this->assertSame([ 'weather' => 'sunny in lisbon' ], $result->output);
    }

    public function test_non_streaming_tool_results_are_carried_into_next_manual_tool_step(): void
    {
        config()->set('superwire.runtime.stream', false);

        $provider = new DroppedToolResultMessageProvider();

        $this->useFakeProvider($provider);

        $result = Workflow::fromFile(__DIR__ . '/../stubs/tool_schema_retry.wire')
            ->withTools([ new BoundSchemaTool(), new RetryWeatherTool() ])
            ->run();

        $this->assertSame([ 'weather' => 'sunny in lisbon' ], $result->output);
    }

    /**
     * @param array<string, mixed> $invalidToolArguments
     */
    private function fakeRetryingToolProvider(array $invalidToolArguments): RetryingToolProvider
    {
        $provider = new RetryingToolProvider($invalidToolArguments);

        $this->useFakeProvider($provider);

        return $provider;
    }
}

final class DroppedToolResultMessageProvider extends ScriptedToolLoopProvider
{
    private bool $secondRequestHadToolResultMessage = false;

    public function __construct()
    {
        parent::__construct([
            fn (TextRequest $request, ScriptedToolLoopProvider $provider) => $this->toolCallResponseWithoutToolResultMessage($request, $provider),
            fn (TextRequest $request, ScriptedToolLoopProvider $provider) => $this->finalizeAfterToolResultWasCarried($request, $provider),
        ]);
    }

    public function secondRequestHadToolResultMessage(): bool
    {
        return $this->secondRequestHadToolResultMessage;
    }

    private function toolCallResponseWithoutToolResultMessage(TextRequest $request, ScriptedToolLoopProvider $provider): TextResponse
    {
        $toolCall = new ToolCall(
            id: 'weather-tool-call',
            name: RetryWeatherTool::name(),
            arguments: [ 'city' => 'lisbon' ],
        );

        $toolResult = $provider->executeToolCall($request, $toolCall);
        $assistantMessage = new AssistantMessage('', collect([ $toolCall ]));

        return $this->toolResponseWithoutToolResultMessage($request, $toolCall, $toolResult, $assistantMessage);
    }

    private function finalizeAfterToolResultWasCarried(TextRequest $request, ScriptedToolLoopProvider $provider): TextResponse
    {
        $this->secondRequestHadToolResultMessage = $this->requestContainsToolResultMessage($request);

        $toolCall = new ToolCall(
            id: 'finalize-success-tool-call',
            name: FinalizeSuccessTool::name(),
            arguments: [
                'result' => [
                    'weather' => 'sunny in lisbon',
                ],
            ],
        );

        return $provider->toolResponse($request, $toolCall, $provider->executeToolCall($request, $toolCall));
    }

    private function requestContainsToolResultMessage(TextRequest $request): bool
    {
        foreach ($request->messages() as $message) {

            if ($message instanceof ToolResultMessage && $message->toolResults->isNotEmpty()) {
                return true;
            }

        }

        return false;
    }

    private function toolResponseWithoutToolResultMessage(TextRequest $request, ToolCall $toolCall, ToolResult $toolResult, AssistantMessage $assistantMessage): TextResponse
    {
        return (new TextResponse('', new Usage, new Meta('fake', 'fake')))
            ->withToolCallsAndResults(collect([ $toolCall ]), collect([ $toolResult ]))
            ->withSteps(new Collection([ new Step('', [ $toolCall ], [ $toolResult ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'fake')) ]))
            ->withMessages(new Collection([
                ...$request->messages(),
                $assistantMessage,
            ]));
    }
}

final class RetryingToolProvider extends ScriptedToolLoopProvider
{
    private ?string $invalidToolResultMessage = null;

    private bool $sawInvalidToolResultOnRetry = false;

    private string|array|null $validToolResult = null;

    /**
     * @param array<string, mixed> $invalidToolArguments
     */
    public function __construct(private readonly array $invalidToolArguments)
    {
        parent::__construct([
            fn (TextRequest $request, ScriptedToolLoopProvider $provider) => $this->invalidToolCallResponse($request, $provider),
            fn (TextRequest $request, ScriptedToolLoopProvider $provider) => $this->validToolCallResponse($request, $provider),
            fn (TextRequest $request, ScriptedToolLoopProvider $provider) => $this->finalSuccessResponse($request, $provider),
        ]);
    }

    public function invalidToolResultMessage(): ?string
    {
        return $this->invalidToolResultMessage;
    }

    public function sawInvalidToolResultOnRetry(): bool
    {
        return $this->sawInvalidToolResultOnRetry;
    }

    public function validToolResult(): string|array|null
    {
        return $this->validToolResult;
    }

    private function invalidToolCallResponse(TextRequest $request, ScriptedToolLoopProvider $provider): TextResponse
    {
        $toolCall = new ToolCall(
            id: 'invalid-retry-weather-tool-call',
            name: RetryWeatherTool::name(),
            arguments: $this->invalidToolArguments,
        );

        $toolResult = $provider->executeToolCall($request, $toolCall);

        $this->invalidToolResultMessage = is_string($toolResult->result) ? $toolResult->result : null;

        return $provider->toolResponse($request, $toolCall, $toolResult);
    }

    private function finalSuccessResponse(TextRequest $request, ScriptedToolLoopProvider $provider): TextResponse
    {
        $toolCall = new ToolCall(
            id: 'finalize-success-tool-call',
            name: FinalizeSuccessTool::name(),
            arguments: [
                'result' => [
                    'weather' => 'sunny in lisbon',
                ],
            ],
        );

        $toolResult = $provider->executeToolCall($request, $toolCall);

        return $provider->toolResponse($request, $toolCall, $toolResult);
    }

    private function validToolCallResponse(TextRequest $request, ScriptedToolLoopProvider $provider): TextResponse
    {
        $this->sawInvalidToolResultOnRetry = $this->requestContainsInvalidToolResult($request);

        $toolCall = new ToolCall(
            id: 'valid-retry-weather-tool-call',
            name: RetryWeatherTool::name(),
            arguments: [
                'city' => 'lisbon',
            ],
        );

        $toolResult = $provider->executeToolCall($request, $toolCall);

        $this->validToolResult = $toolResult->result;

        return $provider->toolResponse($request, $toolCall, $toolResult);
    }

    private function requestContainsInvalidToolResult(TextRequest $request): bool
    {
        foreach ($request->messages() as $message) {

            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->toolResults as $toolResult) {

                if ($toolResult->name !== RetryWeatherTool::name()) {
                    continue;
                }

                if ($toolResult->result === $this->invalidToolResultMessage) {
                    return true;
                }

            }

        }

        return false;
    }
}

final class RetryWeatherTool extends AbstractTool
{
    private static int $handleCallCount = 0;

    public static function reset(): void
    {
        self::$handleCallCount = 0;
    }

    public static function handleCallCount(): int
    {
        return self::$handleCallCount;
    }

    protected function handle(RetryWeatherToolInput $agentInput): array
    {
        self::$handleCallCount++;

        return [
            'weather' => sprintf('sunny in %s', $agentInput->city),
        ];
    }
}

final class BoundSchemaTool extends AbstractTool
{
    protected function handle(BoundSchemaToolInput $agentInput, BoundSchemaToolBoundInput $boundInput): array
    {
        return [
            'value' => sprintf('%s-%s', $agentInput->city, $boundInput->tenant_id),
        ];
    }
}

final class BoundSchemaToolInput extends WorkflowToolInput
{
    public function __construct(
        public string $city,
    )
    {
    }
}

final class BoundSchemaToolBoundInput extends WorkflowBoundInput
{
    public function __construct(
        public string $tenant_id,
    )
    {
    }
}

final class RetryWeatherToolInput extends WorkflowToolInput
{
    public function __construct(
        public string $city,
    )
    {
    }
}
