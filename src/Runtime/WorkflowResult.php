<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class WorkflowResult implements Arrayable, JsonSerializable
{
    public function __construct(
        public mixed $output,
        public array $history,
        public array $context = [],
    )
    {
    }

    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'history' => $this->history,
            'context' => $this->context,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
