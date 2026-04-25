<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner\Agent\Concerns;

use Laravel\Ai\Enums\Lab;

trait ProvidesSuperwireProviderOptions
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
