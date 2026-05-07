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

class RemoteWorkflowExecutor implements WorkflowExecutor
{
    public function __construct(
        private string $baseUrl,
        private int $timeout,
    )
    {
    }

    /**
     * @throws ConnectionException
     */
    public function execute(string $sourceBase64, array $input = [], array $secrets = []): WorkflowResult
    {
        $response = $this->client()->post("{$this->baseUrl}/execute", $this->payload($sourceBase64, $input, $secrets));

        if ($response->failed()) {

            throw new RuntimeException($this->formatMissingValueErrorMessage(sprintf(
                'Workflow execution failed with status %d: %s',
                $response->status(),
                $response->body(),
            )));

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
    public function executeStream(string $sourceBase64, array $input = [], array $secrets = []): Generator
    {
        $response = $this->client()->withOptions([ 'stream' => true ])->post("{$this->baseUrl}/execute/stream", $this->payload(
            $sourceBase64,
            $input,
            $secrets,
        ));

        if ($response->failed()) {

            throw new RuntimeException($this->formatMissingValueErrorMessage(sprintf(
                'Workflow stream request failed with status %d: %s',
                $response->status(),
                $response->body(),
            )));

        }

        yield from SseResponse::parse($response);
    }

    /**
     * @throws ConnectionException
     */
    public function executeStreamToResult(string $sourceBase64, array $input = [], array $secrets = []): WorkflowResult
    {
        $events = [];
        $output = null;

        foreach ($this->executeStream($sourceBase64, $input, $secrets) as $event) {

            $events[] = $event;

            if ($event->kind === ExecutorEventKind::WorkflowCompleted) {
                $output = $event->output();
            }

            if ($event->kind === ExecutorEventKind::WorkflowFailed) {

                throw new RuntimeException($this->formatMissingValueErrorMessage(sprintf(
                    'Workflow execution failed: %s',
                    $event->event->message ?? 'Unknown error',
                )));

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
    private function payload(string $sourceBase64, array $input, array $secrets): array
    {
        return [
            'workflow_source_base64' => $sourceBase64,
            'input' => $input ?: (object) [],
            'secrets' => $secrets ?: (object) [],
        ];
    }

    private function formatMissingValueErrorMessage(string $message): string
    {
        if (!$this->containsMissingRequiredValueError($message)) {
            return $message;
        }

        return sprintf(
            '%s This usually means a required workflow input, secret, or environment value was not provided. Check your ->inputs([...]) and ->secrets([...]) values.',
            $message,
        );
    }

    private function containsMissingRequiredValueError(string $message): bool
    {
        return preg_match('/(missing|required|not provided|undefined|not found|unknown).*(input|secret|env|environment|variable|key)|(input|secret|env|environment|variable|key).*(missing|required|not provided|undefined|not found|unknown)/i', $message) === 1;
    }
}
