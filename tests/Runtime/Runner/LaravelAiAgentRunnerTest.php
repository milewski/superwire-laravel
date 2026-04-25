<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime\Runner;

use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Runtime\AgentInvocation;
use Superwire\Laravel\Runtime\Runner\LaravelAiAgentRunner;
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
        $runner = new LaravelAiAgentRunner($ai, $this->app[ 'config' ]);

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
    }

    public function test_it_returns_structured_laravel_ai_responses_as_arrays(): void
    {
        $provider = new RecordingTextProvider(
            response: new StructuredAgentResponse(
                invocationId: 'invocation-1',
                structured: [
                    'summary' => 'Superwire summary',
                    'tagline' => 'Ship it',
                ],
                text: '{"summary":"Superwire summary","tagline":"Ship it"}',
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
            ),
        );

        $this->assertSame(
            expected: [
                'summary' => 'Superwire summary',
                'tagline' => 'Ship it',
            ],
            actual: $output,
        );
    }

    private function invocation(string $prompt, string $model, array $providerConfig): AgentInvocation
    {
        $definition = $this->app->make(WorkflowCompiler::class)->compile(
            workflowPath: __DIR__ . '/../../Stubs/greeting.wire',
        );

        return new AgentInvocation(
            agent: $definition->agents->findByName(name: 'greeting'),
            provider: $definition->providers->findByName(name: 'openai'),
            model: $model,
            prompt: $prompt,
            providerConfig: $providerConfig,
            inputs: [],
            secrets: [],
            agentOutputs: [],
        );
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
