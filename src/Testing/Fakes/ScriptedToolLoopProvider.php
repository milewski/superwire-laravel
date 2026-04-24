<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Testing\Fakes;

use Generator;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use RuntimeException;

class ScriptedToolLoopProvider extends AbstractToolLoopProvider
{
    /**
     * @var array<int, callable(TextRequest, self): TextResponse>
     */
    private array $steps;

    /**
     * @param array<int, callable(TextRequest, self): TextResponse> $steps
     */
    public function __construct(array $steps)
    {
        parent::__construct();

        $this->steps = array_values($steps);
    }

    public function text(TextRequest $request): TextResponse
    {
        $stepIndex = count($this->requests()) - 1;
        $step = $this->steps[ $stepIndex ] ?? null;

        if (!is_callable($step)) {
            throw new RuntimeException(sprintf('No scripted tool-loop step registered for request #%d.', $stepIndex + 1));
        }

        return $step($request, $this);
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function streamFake(TextRequest $request): Generator
    {
        throw new RuntimeException('Streaming is not supported by ScriptedToolLoopProvider.');
    }
}
