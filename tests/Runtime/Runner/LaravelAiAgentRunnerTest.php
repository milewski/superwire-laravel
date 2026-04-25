<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime\Runner;

use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\StructuredAnonymousAgent;
use RuntimeException;
use Superwire\Laravel\Contracts\StreamableAgentRunner;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\Runner\LaravelAiAgentRunner;
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

        $output = $runner->run(
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

        $this->assertSame('Welcome aboard.', actual: $output);
        $this->assertSame('openai', actual: $ai->providerName);
        $this->assertSame('Write a short welcome message.', actual: $provider->prompt->prompt);
        $this->assertSame('test-model', actual: $provider->prompt->model);
        $this->assertSame('openai', actual: $this->app[ 'config' ]->get('ai.providers.openai.driver'));
        $this->assertSame('test-key', actual: $this->app[ 'config' ]->get('ai.providers.openai.key'));
        $this->assertSame('http://example.test/v1', actual: $this->app[ 'config' ]->get('ai.providers.openai.url'));
        $this->assertNull(actual: $this->app[ 'config' ]->get('ai.providers.openai.api_key'));
        $this->assertNull(actual: $this->app[ 'config' ]->get('ai.providers.openai.endpoint'));
        $this->assertInstanceOf(AnonymousAgent::class, $provider->prompt->agent);
        $this->assertNotInstanceOf(StructuredAnonymousAgent::class, $provider->prompt->agent);
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

        $output = $runner->run(
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
            actual: $output,
        );

        $this->assertInstanceOf(StructuredAnonymousAgent::class, $provider->prompt->agent);
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

        $output = $runner->run(
            invocation: $this->invocation(
                prompt: 'Write a short welcome message.',
                model: 'test-model',
                providerConfig: [ 'driver' => 'openai' ],
                wire: $this->wireWithOutput(output: 'number'),
            ),
        );

        $this->assertInstanceOf(AnonymousAgent::class, $provider->prompt->agent);
        $this->assertNotInstanceOf(StructuredAnonymousAgent::class, $provider->prompt->agent);
        $this->assertSame(expected: '42', actual: $output);
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

    private function invocation(string $prompt, string $model, array $providerConfig, ?string $wire = null): AgentInvocation
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
        private readonly AgentResponse | StructuredAgentResponse $response,
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
