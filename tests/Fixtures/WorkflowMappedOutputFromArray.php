<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Fixtures;

final readonly class WorkflowMappedOutputFromArray
{
    public function __construct(
        public string $review,
    )
    {
    }

    public static function fromArray(array $output): self
    {
        return new self(review: strtoupper($output[ 'review' ]));
    }
}
