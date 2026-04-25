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
use Laravel\Ai\StructuredAnonymousAgent;
use RuntimeException;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\Runner\LaravelAiAgentRunner;
use Superwire\Laravel\Runtime\Tool\BoundToolDefinition;
use Superwire\Laravel\Runtime\Tool\LaravelAiTool;
use Superwire\Laravel\Runtime\Tool\ToolInvoker;
use Superwire\Laravel\Runtime\Tool\ToolRegistry;
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
            toolInvoker: new ToolInvoker(registry: new ToolRegistry()),
        );

        $output = $runner->run(
            invocation: $this->invocation(
                prompt: 'Write a short welcome message.',
                model: 'test-model',
                providerConfig: [
                    'driver' => 'openai',
                    'api_key' => 'test-key',
                ],
            ),
        );

        $this->assertSame('Welcome aboard.', actual: $output);
        $this->assertSame('openai', actual: $ai->providerName);
        $this->assertSame('Write a short welcome message.', actual: $provider->prompt->prompt);
        $this->assertSame('test-model', actual: $provider->prompt->model);
        $this->assertSame('openai', actual: $this->app[ 'config' ]->get('ai.providers.openai.driver'));
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
            toolInvoker: new ToolInvoker(registry: new ToolRegistry()),
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
            toolInvoker: new ToolInvoker(registry: new ToolRegistry()),
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
            toolInvoker: new ToolInvoker(registry: new ToolRegistry()),
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
                ),
            ],
        ));

        $tools = $provider->prompt->agent->tools();

        $this->assertCount(expectedCount: 1, haystack: $tools);
        $this->assertInstanceOf(expected: LaravelAiTool::class, actual: $tools[ 0 ]);
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

    public function __construct(private readonly AgentResponse | StructuredAgentResponse $response)
    {
    }

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        $this->prompt = $prompt;

        return $this->response;
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        throw new RuntimeException('Streaming is not used by these tests.');
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
