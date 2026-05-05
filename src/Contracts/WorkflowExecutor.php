<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Contracts;

use Generator;
use Superwire\Laravel\Runtime\ExecutorEvent;
use Superwire\Laravel\Runtime\WorkflowResult;

interface WorkflowExecutor
{
    public function execute(string $sourceBase64, array $input = [], array $secrets = []): WorkflowResult;

    /**
     * @return Generator<ExecutorEvent>
     */
    public function executeStream(string $sourceBase64, array $input = [], array $secrets = []): Generator;

    public function executeStreamToResult(string $sourceBase64, array $input = [], array $secrets = []): WorkflowResult;
}
