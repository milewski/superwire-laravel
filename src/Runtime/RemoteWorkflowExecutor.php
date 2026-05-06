<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Superwire\Laravel\Contracts\WorkflowExecutor;
use Superwire\Laravel\Enums\ExecutorEventKind;
use Superwire\Laravel\Enums\ModelResponseFormat;

class RemoteWorkflowExecutor implements WorkflowExecutor
{
    public function __construct(
        private string $baseUrl,
        private int $timeout,
        private ModelResponseFormat $responseFormat = ModelResponseFormat::Auto,
    )
    {
    }

    /**
     * @throws ConnectionException
     */
    public function execute(string $sourceBase64, array $input = [], array $secrets = [], ?ModelResponseFormat $responseFormat = null): WorkflowResult
    {
        $response = $this->client()->post("{$this->baseUrl}/execute", $this->payload($sourceBase64, $input, $secrets, $responseFormat));

        if ($response->failed()) {

            throw new RuntimeException(sprintf(
                'Workflow execution failed with status %d: %s',
                $response->status(),
                $response->body(),
            ));

        }

        $json = $response->json();

        return new WorkflowResult(
            output: $json[ 'output' ] ?? null,
            history: [],
            context: [
                'input' => $input,
                'secrets' => $secrets,
            ],
        );
    }

    /**
     * @throws ConnectionException
     */
    public function executeStream(string $sourceBase64, array $input = [], array $secrets = [], ?ModelResponseFormat $responseFormat = null): Generator
    {
        $response = $this->client()->withOptions([ 'stream' => true ])->post("{$this->baseUrl}/execute/stream", $this->payload(
            $sourceBase64,
            $input,
            $secrets,
            $responseFormat,
        ));

        if ($response->failed()) {

            throw new RuntimeException(sprintf(
                'Workflow stream request failed with status %d: %s',
                $response->status(),
                $response->body(),
            ));

        }

        yield from SseResponse::parse($response);
    }

    /**
     * @throws ConnectionException
     */
    public function executeStreamToResult(string $sourceBase64, array $input = [], array $secrets = [], ?ModelResponseFormat $responseFormat = null): WorkflowResult
    {
        $events = [];
        $output = null;

        foreach ($this->executeStream($sourceBase64, $input, $secrets, $responseFormat) as $event) {

            $events[] = $event;

            if ($event->kind === ExecutorEventKind::WorkflowCompleted) {
                $output = $event->output();
            }

            if ($event->kind === ExecutorEventKind::WorkflowFailed) {

                throw new RuntimeException(sprintf(
                    'Workflow execution failed: %s',
                    $event->event->message ?? 'Unknown error',
                ));

            }

        }

        return new WorkflowResult(
            output: $output,
            history: array_map(callback: static fn (ExecutorEvent $event): array => $event->toArray(), array: $events),
            context: [
                'input' => $input,
                'secrets' => $secrets,
            ],
        );
    }

    private function client(): PendingRequest
    {
        return Http::timeout($this->timeout);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $sourceBase64, array $input, array $secrets, ?ModelResponseFormat $responseFormat): array
    {
        $responseFormat ??= $this->responseFormat;

        return [
            'workflow_source_base64' => $sourceBase64,
            'input' => $input ?: (object) [],
            'secrets' => $secrets ?: (object) [],
            'options' => [
                'response_format' => $responseFormat->value,
            ],
        ];
    }
}
