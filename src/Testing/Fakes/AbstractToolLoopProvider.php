<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Testing\Fakes;

use Generator;
use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Superwire\Laravel\Testing\ToolCallFactory;
use Superwire\Laravel\Tools\Internal\FinalizeErrorTool;
use Superwire\Laravel\Tools\Internal\FinalizeSuccessTool;
use Superwire\Laravel\Tools\WorkflowTool;

abstract class AbstractToolLoopProvider implements TextProvider, TextGateway
{
    /** @var array<int, TextRequest> */
    private array $requests = [];

    /** @var array<int, TextRequest> */
    private array $textRequests = [];

    /** @var array<int, array<string, mixed>> */
    private array $providerConfigs = [];

    /** @var array<int, ToolCall> */
    private array $toolCalls = [];

    /** @var array<int, ToolResult> */
    private array $toolResults = [];

    public function __construct()
    {
    }

    abstract public function text(TextRequest $request): TextResponse;

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        throw new RuntimeException('Promptable agents are not used by Superwire fakes.');
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        throw new RuntimeException('Streaming is not supported by Superwire fakes.');
    }

    public function textGateway(): TextGateway
    {
        return $this;
    }

    public function useTextGateway(TextGateway $gateway): self
    {
        return $this;
    }

    public function defaultTextModel(): string
    {
        return 'fake';
    }

    public function cheapestTextModel(): string
    {
        return 'fake';
    }

    public function smartestTextModel(): string
    {
        return 'fake';
    }

    public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
    {
        $maxSteps = $options?->maxSteps ?? 20;

        for ($step = 0; $step < $maxSteps; $step++) {
            $request = new TextRequest($instructions, $messages, $tools, $model, $options);
            $this->recordTextRequest($request);

            try {
                $response = $this->text($request);
            } catch (RuntimeException $exception) {
                if (isset($response) && str_contains($exception->getMessage(), 'No scripted tool-loop step registered')) {
                    return $response;
                }

                throw $exception;
            }

            if ($this->responseIsFinal($response)) {
                return $response;
            }

            $messages = $response->messages->all();
        }

        return $response ?? new TextResponse('', new Usage, new Meta('fake', 'fake'));
    }

    private function responseIsFinal(TextResponse $response): bool
    {
        if ($response->toolCalls->isEmpty()) {
            return true;
        }

        return $response->toolCalls->contains(
            fn (ToolCall $toolCall): bool => in_array($toolCall->name, [ FinalizeSuccessTool::name(), FinalizeErrorTool::name() ], true),
        );
    }

    public function streamText(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): Generator
    {
        throw new RuntimeException('Streaming is not supported by Superwire fakes.');
    }

    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        return $this;
    }

    protected function recordTextRequest(TextRequest $request): void
    {
        $this->requests[] = $request;
        $this->textRequests[] = $request;
    }

    public function recordProviderConfig(array $providerConfig): void
    {
        $this->providerConfigs[] = $providerConfig;
    }

    public function request(int $index): ?TextRequest
    {
        return $this->requests[ $index ] ?? null;
    }

    /** @return array<int, TextRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @return array<int, TextRequest> */
    public function textRequests(): array
    {
        return $this->textRequests;
    }

    /** @return array<int, TextRequest> */
    public function streamRequests(): array
    {
        return [];
    }

    public function providerConfigs(): array
    {
        return $this->providerConfigs;
    }

    public function toolCall(int $index): ?ToolCall
    {
        return $this->toolCalls[ $index ] ?? null;
    }

    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    public function toolResult(int $index): ?ToolResult
    {
        return $this->toolResults[ $index ] ?? null;
    }

    public function toolResults(): array
    {
        return $this->toolResults;
    }

    public function registeredTool(string $toolName, int $requestIndex = 0): ?Tool
    {
        return $this->resolveTool($toolName, $this->request($requestIndex)?->tools() ?? [], false);
    }

    public function executeToolCall(TextRequest $request, ToolCall $toolCall): ToolResult
    {
        $tool = $this->resolveTool($toolCall->name, $request->tools());
        $output = $tool->handle(new \Laravel\Ai\Tools\Request($toolCall->arguments));

        $toolResult = new ToolResult($toolCall->id, $toolCall->name, $toolCall->arguments, (string) $output, $toolCall->resultId);

        $this->toolCalls[] = $toolCall;
        $this->toolResults[] = $toolResult;

        return $toolResult;
    }

    public function callTool(TextRequest $request, ToolCall $toolCall): TextResponse
    {
        return $this->toolResponse($request, $toolCall, $this->executeToolCall($request, $toolCall));
    }

    public function callToolClass(TextRequest $request, string|WorkflowTool $tool, array $arguments = [], ?string $toolCallId = null): TextResponse
    {
        return $this->callTool($request, ToolCallFactory::fromClass($tool, $arguments, $toolCallId));
    }

    public function finalizeSuccessResponse(TextRequest $request, mixed $result, string $toolCallId = 'fake-finalize-success'): TextResponse
    {
        return $this->callTool($request, ToolCallFactory::fromClass(FinalizeSuccessTool::class, [ 'result' => $result ], $toolCallId));
    }

    public function finalizeErrorResponse(TextRequest $request, string $message, string $toolCallId = 'fake-finalize-error'): TextResponse
    {
        return $this->callTool($request, ToolCallFactory::fromClass(FinalizeErrorTool::class, [ 'message' => $message ], $toolCallId));
    }

    public function toolResponse(TextRequest $request, ToolCall $toolCall, ToolResult $toolResult): TextResponse
    {
        $assistantMessage = new AssistantMessage('', collect([ $toolCall ]));
        $toolResultMessage = new ToolResultMessage(collect([ $toolResult ]));

        return (new TextResponse('', new Usage, new Meta('fake', 'fake')))
            ->withSteps(collect([ new Step('', [ $toolCall ], [ $toolResult ], FinishReason::ToolCalls, new Usage, new Meta('fake', 'fake')) ]))
            ->withMessages(new Collection([ ...$request->messages(), $assistantMessage, $toolResultMessage ]));
    }

    public function textResponse(TextRequest $request, string $text): TextResponse
    {
        return (new TextResponse($text, new Usage, new Meta('fake', 'fake')))
            ->withSteps(collect([ new Step($text, [], [], FinishReason::Stop, new Usage, new Meta('fake', 'fake')) ]))
            ->withMessages(new Collection([ ...$request->messages(), new AssistantMessage($text) ]));
    }

    protected function resolveTool(string $name, array $tools, bool $throw = true): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof Tool && class_basename($tool) === $name) {
                return $tool;
            }
        }

        if (!$throw) {
            return null;
        }

        throw new RuntimeException(sprintf('Tool not found in fake provider: %s', $name));
    }
}
