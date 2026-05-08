<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Contracts;

use Generator;
use Superwire\Laravel\Runtime\ExecutorEvent;
use Superwire\Laravel\Runtime\WorkflowFormatResult;
use Superwire\Laravel\Runtime\WorkflowResult;
use Superwire\Laravel\Runtime\WorkflowValidationResult;

interface WorkflowExecutor
{
    public function execute(string $sourceBase64, array $input = [], array $secrets = []): WorkflowResult;

    /**
     * @return Generator<ExecutorEvent>
     */
    public function executeStream(string $sourceBase64, array $input = [], array $secrets = []): Generator;

    public function executeStreamToResult(string $sourceBase64, array $input = [], array $secrets = []): WorkflowResult;

    public function validate(string $sourceBase64, array $input = [], array $secrets = []): WorkflowValidationResult;

    public function format(string $sourceBase64): WorkflowFormatResult;
}
