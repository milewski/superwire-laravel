<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner;

use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\StructuredAnonymousAgent;

final class SuperwireStructuredAnonymousAgent extends StructuredAnonymousAgent implements HasProviderOptions
{
    public function providerOptions(Lab | string $provider): array
    {
        return match ($provider) {
            Lab::OpenAI, Lab::xAI, Lab::OpenAI->value, Lab::xAI->value => [
                'reasoning' => [ 'effort' => 'none' ],
            ],
            default => [],
        };
    }
}
