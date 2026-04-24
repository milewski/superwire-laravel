<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Testing\Fakes;

use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Throwable;

class ToolLoopProvider extends AbstractToolLoopProvider
{
    /**
     * @param array<string, mixed> $resultsByPrompt
     */
    public function __construct(private readonly array $resultsByPrompt)
    {
        parent::__construct();
    }

    public function text(TextRequest $request): TextResponse
    {
        return $this->responseForRequest($request);
    }

    private function responseForRequest(TextRequest $request): TextResponse
    {
        $result = $this->resultForPrompt($request->prompt());

        if ($result instanceof FinalizeErrorResponse || (is_object($result) && class_basename($result) === 'FinalizeErrorResponse')) {
            return $this->finalizeErrorResponse($request, $result->message);
        }

        if ($result instanceof NoFinalizationResponse || (is_object($result) && class_basename($result) === 'NoFinalizationResponse')) {
            return $this->textResponse($request, $result->text);
        }

        return $this->finalizeSuccessResponse($request, $result);
    }

    private function resultForPrompt(?string $prompt): mixed
    {
        if ($prompt === null || !array_key_exists($prompt, $this->resultsByPrompt)) {
            throw new RuntimeException(sprintf('No fake tool-loop response registered for prompt: %s', $prompt ?? 'null'));
        }

        $result = $this->resultsByPrompt[ $prompt ];

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
