<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime\Runner;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\StructuredAnonymousAgent;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Superwire\Laravel\Contracts\StreamableAgentRunner;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Enums\OutputStrategy;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\Runner\LaravelAiAgentRunner;
use Superwire\Laravel\Runtime\Runner\Output\OutputAbortTool;
use Superwire\Laravel\Runtime\Runner\Output\OutputSuccessTool;
use Superwire\Laravel\Runtime\Tool\BoundToolDefinition;
use Superwire\Laravel\Runtime\Tool\LaravelAiTool;
use Superwire\Laravel\Tests\TestCase;

final class LaravelAiAgentRunnerTest extends TestCase
{
    public function test_it_sends_the_invocation_prompt_model_and_provider_to_laravel_ai(): void
    {
        $provider = new RecordingTextProvider(
            response: new AgentResponse(
                invocationId: 'invocation-1',
                text: 'Welcome aboard.',
                usage: new Usage(),
                meta: new Meta(),
            ),
        );

        $ai = new RecordingAiManager(app: $this->app, provider: $provider);
        $runner = new LaravelAiAgentRunner(
            ai: $ai,
            config: $this->app[ 'config' ],
        );

        $result = $runner->run(
            invocation: $this->invocation(
                prompt: 'Write a short welcome message.',
                model: 'test-model',
                providerConfig: [
                    'driver' => 'openai',
                    'api_key' => 'test-key',
                    'endpoint' => 'http://example.test/v1',
                ],
            ),
        );

        $this->assertSame('Welcome aboard.', actual: $result->output);
        $this->assertSame(expected: 'user', actual: $result->history[ 0 ][ 'role' ]);
        $this->assertSame(expected: 'assistant', actual: $result->history[ 1 ][ 'role' ]);
        $this->assertSame('openai', actual: $ai->providerName);
        $this->assertSame('Write a short welcome message.', actual: $provider->prompt->prompt);
        $this->assertSame('test-model', actual: $provider->prompt->model);
        $this->assertSame('openai', actual: $this->app[ 'config' ]->get('ai.providers.openai.driver'));
        $this->assertSame('test-key', actual: $this->app[ 'config' ]->get('ai.providers.openai.key'));
        $this->assertSame('http://example.test/v1', actual: $this->app[ 'config' ]->get('ai.providers.openai.url'));
        $this->assertNull(actual: $this->app[ 'config' ]->get('ai.providers.openai.api_key'));
        $this->assertNull(actual: $this->app[ 'config' ]->get('ai.providers.openai.endpoint'));
        $this->assertInstanceOf(AnonymousAgent::class, $provider->prompt->agent);
        $this->assertInstanceOf(HasProviderOptions::class, $provider->prompt->agent);
        $this->assertSame(expected: [ 'reasoning' => [ 'effort' => 'none' ] ], actual: $provider->prompt->agent->providerOptions('openai'));
        $this->assertNotInstanceOf(HasStructuredOutput::class, $provider->prompt->agent);
    }

    public function test_it_uses_structured_agent_for_object_outputs(): void
    {
        $provider = new RecordingTextProvider(
            response: new StructuredAgentResponse(
                invocationId: 'invocation-1',
                structured: [
                    'summary' => 'Superwire summary',
                    'tagline' => 'Ship it',
                ],
                text: json_encode([ 'summary' => 'Superwire summary', 'tagline' => 'Ship it' ]),
                usage: new Usage(),
                meta: new Meta(),
            ),
        );

        $runner = new LaravelAiAgentRunner(
            ai: new RecordingAiManager(app: $this->app, provider: $provider),
            config: $this->app[ 'config' ],
        );

        $result = $runner->run(
            invocation: $this->invocation(
                prompt: 'Summarize Superwire.',
                model: 'test-model',
                providerConfig: [ 'driver' => 'openai' ],
                wire: $this->wireWithOutput(output: "{ summary: string\n tagline: string }"),
            ),
        );

        $this->assertSame(
            expected: [
                'summary' => 'Superwire summary',
                'tagline' => 'Ship it',
            ],
            actual: $result->output,
        );

        $this->assertInstanceOf(StructuredAnonymousAgent::class, $provider->prompt->agent);
        $this->assertInstanceOf(HasStructuredOutput::class, $provider->prompt->agent);
        $this->assertInstanceOf(HasProviderOptions::class, $provider->prompt->agent);
        $this->assertSame(expected: [ 'reasoning' => [ 'effort' => 'none' ] ], actual: $provider->prompt->agent->providerOptions('openai'));

        $schema = $provider->prompt->agent->schema(new JsonSchemaTypeFactory());

        $this->assertArrayHasKey(key: 'summary', array: $schema);
        $this->assertArrayHasKey(key: 'tagline', array: $schema);
        $objectSchema = new ObjectSchema($schema)->toSchema();

        $this->assertSame(expected: 'object', actual: $objectSchema[ 'type' ]);
        $this->assertFalse(condition: $objectSchema[ 'additionalProperties' ]);
        $this->assertSame(expected: [ 'summary', 'tagline' ], actual: $objectSchema[ 'required' ]);
        $this->assertSame(expected: [ 'type' => 'string' ], actual: $objectSchema[ 'properties' ][ 'summary' ]);
        $this->assertSame(expected: [ 'type' => 'string' ], actual: $objectSchema[ 'properties' ][ 'tagline' ]);
    }

    public function test_laravel_ai_sends_openai_responses_structured_json_schema_request(): void
    {
        Http::fake([
            'http://example.test/v1/responses' => Http::response([
                'id' => 'response-1',
                'model' => 'test-model',
                'status' => 'completed',
                'output' => [ [
                    'type' => 'message',
                    'status' => 'completed',
                    'content' => [ [
                        'type' => 'output_text',
                        'text' => json_encode([ 'summary' => 'Superwire summary', 'tagline' => 'Ship it' ]),
                    ] ],
                ] ],
                'usage' => [
                    'input_tokens' => 1,
                    'output_tokens' => 1,
                ],
            ]),
        ]);

        $result = app(LaravelAiAgentRunner::class)->run(
            invocation: $this->invocation(
                prompt: 'Summarize Superwire.',
                model: 'test-model',
                providerConfig: [
                    'driver' => 'openai',
                    'api_key' => 'test-key',
                    'endpoint' => 'http://example.test/v1',
                ],
                wire: $this->wireWithOutput(output: "{ summary: string\n tagline: string }"),
            ),
        );

        $this->assertSame(expected: [ 'summary' => 'Superwire summary', 'tagline' => 'Ship it' ], actual: $result->output);

        Http::assertSent(function ($request): bool {

            $body = $request->data();

            return str_ends_with($request->url(), '/responses')
                && ($body[ 'text' ][ 'format' ][ 'type' ] ?? null) === 'json_schema'
                && ($body[ 'text' ][ 'format' ][ 'strict' ] ?? null) === true
                && ($body[ 'reasoning' ][ 'effort' ] ?? null) === 'none'
                && array_key_exists('summary', $body[ 'text' ][ 'format' ][ 'schema' ][ 'properties' ] ?? []);

        });
    }

    public function test_it_can_use_tool_calling_strategy_for_structured_outputs(): void
    {
        $response = new AgentResponse(
            invocationId: 'invocation-1',
            text: '',
            usage: new Usage(),
            meta: new Meta(),
        );

        $response->withToolCallsAndResults(
            toolCalls: new Collection(),
            toolResults: new Collection([
                new ToolResult(
                    id: 'tool-result-1',
                    name: 'OutputSuccessTool',
                    arguments: [ 'summary' => 'Superwire summary', 'tagline' => 'Ship it' ],
                    result: json_encode([
                        'superwire_output_success' => true,
                        'output' => [ 'summary' => 'Superwire summary', 'tagline' => 'Ship it' ],
                    ]),
                ),
            ]),
        );

        $provider = new RecordingTextProvider(response: $response);
        $runner = new LaravelAiAgentRunner(
            ai: new RecordingAiManager(app: $this->app, provider: $provider),
            config: $this->app[ 'config' ],
        );

        $result = $runner->run(
            invocation: $this->invocation(
                prompt: 'Summarize Superwire.',
                model: 'test-model',
                providerConfig: [ 'driver' => 'openai' ],
                wire: $this->wireWithOutput(output: "{ summary: string\n tagline: string }"),
                outputStrategy: OutputStrategy::ToolCalling,
            ),
        );

        $this->assertSame(expected: [ 'summary' => 'Superwire summary', 'tagline' => 'Ship it' ], actual: $result->output);
        $this->assertInstanceOf(expected: OutputSuccessTool::class, actual: $provider->prompt->agent->tools()[ 0 ]);
        $this->assertInstanceOf(expected: OutputAbortTool::class, actual: $provider->prompt->agent->tools()[ 1 ]);
    }

    public function test_output_success_tool_reports_invalid_schema_arguments(): void
    {
        $provider = new RecordingTextProvider(response: new AgentResponse(
            invocationId: 'invocation-1',
            text: '',
            usage: new Usage(),
            meta: new Meta(),
        ));

        $runner = new LaravelAiAgentRunner(
            ai: new RecordingAiManager(app: $this->app, provider: $provider),
            config: $this->app[ 'config' ],
        );

        try {

            $runner->run(invocation: $this->invocation(
                prompt: 'Summarize Superwire.',
                model: 'test-model',
                providerConfig: [ 'driver' => 'openai' ],
                wire: $this->wireWithOutput(output: "{ summary: string\n tagline: string }"),
                outputStrategy: OutputStrategy::ToolCalling,
            ));

        } catch (InvalidArgumentException) {
        }

        $result = $provider->prompt->agent->tools()[ 0 ]->handle(new Request([ 'summary' => 'Only summary' ]));

        $this->assertStringContainsString(needle: 'error', haystack: $result);
        $this->assertStringContainsString(needle: 'tagline', haystack: $result);
    }

    public function test_tool_calling_strategy_can_abort_outputs(): void
    {
        $response = new AgentResponse(
            invocationId: 'invocation-1',
            text: '',
            usage: new Usage(),
            meta: new Meta(),
        );

        $response->withToolCallsAndResults(
            toolCalls: new Collection(),
            toolResults: new Collection([
                new ToolResult(
                    id: 'tool-result-1',
                    name: 'OutputAbortTool',
                    arguments: [ 'reason' => 'Insufficient information.' ],
                    result: json_encode([
                        'superwire_output_abort' => true,
                        'reason' => 'Insufficient information.',
                    ]),
                ),
            ]),
        );

        $runner = new LaravelAiAgentRunner(
            ai: new RecordingAiManager(app: $this->app, provider: new RecordingTextProvider(response: $response)),
            config: $this->app[ 'config' ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent aborted output: Insufficient information.');

        $runner->run(
            invocation: $this->invocation(
                prompt: 'Summarize Superwire.',
                model: 'test-model',
                providerConfig: [ 'driver' => 'openai' ],
                wire: $this->wireWithOutput(output: "{ summary: string\n tagline: string }"),
                outputStrategy: OutputStrategy::ToolCalling,
            ),
        );
    }

    public function test_it_returns_raw_text_for_non_object_outputs(): void
    {
        $provider = new RecordingTextProvider(
            response: new AgentResponse(
                invocationId: 'invocation-1',
                text: '42',
                usage: new Usage(),
                meta: new Meta(),
            ),
        );

        $runner = new LaravelAiAgentRunner(
            ai: new RecordingAiManager(app: $this->app, provider: $provider),
            config: $this->app[ 'config' ],
        );

        $result = $runner->run(
            invocation: $this->invocation(
                prompt: 'Write a short welcome message.',
                model: 'test-model',
                providerConfig: [ 'driver' => 'openai' ],
                wire: $this->wireWithOutput(output: 'number'),
            ),
        );

        $this->assertInstanceOf(AnonymousAgent::class, $provider->prompt->agent);
        $this->assertNotInstanceOf(HasStructuredOutput::class, $provider->prompt->agent);
        $this->assertSame(expected: '42', actual: $result->output);
    }

    public function test_it_exposes_bound_tools_to_laravel_ai_agent(): void
    {
        $provider = new RecordingTextProvider(
            response: new AgentResponse(
                invocationId: 'invocation-1',
                text: 'Weather is sunny.',
                usage: new Usage(),
                meta: new Meta(),
            ),
        );

        $definition = app(WorkflowCompiler::class)->compile(
            workflowPath: __DIR__ . '/../../Stubs/tool_schema_retry.wire',
        );

        $runner = new LaravelAiAgentRunner(
            ai: new RecordingAiManager(app: $this->app, provider: $provider),
            config: $this->app[ 'config' ],
        );

        $runner->run(invocation: new AgentInvocation(
            agent: $definition->agents->findByName(name: 'assistant'),
            provider: $definition->providers->findByName(name: 'openai'),
            model: 'test-model',
            prompt: 'Use a tool.',
            providerConfig: [ 'driver' => 'openai' ],
            inputs: [],
            secrets: [],
            agentOutputs: [],
            tools: [
                new BoundToolDefinition(
                    definition: $definition->toolDefinitionNamed(toolName: 'bound_schema_tool'),
                    bounded: [ 'tenant_id' => 'tenant-123' ],
                    runId: 'run-1',
                    agentName: 'assistant',
                ),
            ],
        ));

        $tools = $provider->prompt->agent->tools();

        $this->assertCount(expectedCount: 1, haystack: $tools);
        $this->assertInstanceOf(expected: LaravelAiTool::class, actual: $tools[ 0 ]);
    }

    public function test_it_streams_using_laravel_ai_stream_api(): void
    {
        $streamResponse = new StreamableAgentResponse(
            invocationId: 'invocation-1',
            generator: fn (): array => [
                new TextDelta(
                    id: 'event-1',
                    messageId: 'message-1',
                    delta: 'Hello ',
                    timestamp: 1,
                ),
                new TextDelta(
                    id: 'event-2',
                    messageId: 'message-1',
                    delta: 'world',
                    timestamp: 2,
                ),
            ],
            meta: new Meta(),
        );

        $provider = new RecordingTextProvider(
            response: new AgentResponse(
                invocationId: 'invocation-1',
                text: 'unused',
                usage: new Usage(),
                meta: new Meta(),
            ),
            streamResponse: $streamResponse,
        );

        $runner = new LaravelAiAgentRunner(
            ai: new RecordingAiManager(app: $this->app, provider: $provider),
            config: $this->app[ 'config' ],
        );

        $this->assertInstanceOf(expected: StreamableAgentRunner::class, actual: $runner);

        $stream = $runner->runStream(invocation: $this->invocation(
            prompt: 'Stream a short welcome message.',
            model: 'test-model',
            providerConfig: [ 'driver' => 'openai' ],
        ));

        $this->assertSame(expected: $streamResponse, actual: $stream);
        $this->assertSame(expected: 'Stream a short welcome message.', actual: $provider->streamPrompt->prompt);
        $this->assertSame(expected: 'test-model', actual: $provider->streamPrompt->model);
        $this->assertSame(expected: 'Hello world', actual: TextDelta::combine(iterator_to_array($stream)));
    }

    private function invocation(string $prompt, string $model, array $providerConfig, ?string $wire = null, OutputStrategy $outputStrategy = OutputStrategy::Structured): AgentInvocation
    {
        $workflowPath = $this->writeTemporaryWorkflow(
            wire: $wire ?? $this->wireWithOutput(output: 'string'),
            prefix: 'superwire-ai-runner-',
        );

        try {
            $definition = app(WorkflowCompiler::class)->compile(workflowPath: $workflowPath);
        } finally {
            unlink(filename: $workflowPath);
        }

        return new AgentInvocation(
            agent: $definition->agents->findByName(name: 'greeting'),
            provider: $definition->providers->findByName(name: 'openai'),
            model: $model,
            prompt: $prompt,
            providerConfig: $providerConfig,
            inputs: [],
            secrets: [],
            agentOutputs: [],
            tools: [],
            outputStrategy: $outputStrategy,
        );
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

final class RecordingAiManager extends AiManager
{
    public ?string $providerName = null;

    public function __construct($app, private readonly TextProvider $provider)
    {
        parent::__construct(app: $app);
    }

    public function textProvider(?string $name = null): TextProvider
    {
        $this->providerName = $name;

        return $this->provider;
    }
}

final class RecordingTextProvider implements TextProvider
{
    public ?AgentPrompt $prompt = null;
    public ?AgentPrompt $streamPrompt = null;

    public function __construct(
        private readonly AgentResponse|StructuredAgentResponse $response,
        private readonly ?StreamableAgentResponse $streamResponse = null,
    )
    {
    }

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        $this->prompt = $prompt;

        return $this->response;
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        $this->streamPrompt = $prompt;

        return $this->streamResponse ?? throw new RuntimeException('Streaming response was not configured.');
    }

    public function textGateway(): TextGateway
    {
        throw new RuntimeException('Gateway access is not used by these tests.');
    }

    public function useTextGateway(TextGateway $gateway): self
    {
        return $this;
    }

    public function defaultTextModel(): string
    {
        return 'default-model';
    }

    public function cheapestTextModel(): string
    {
        return 'cheap-model';
    }

    public function smartestTextModel(): string
    {
        return 'smart-model';
    }
}
