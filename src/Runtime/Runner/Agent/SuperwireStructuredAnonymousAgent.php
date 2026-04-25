<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner\Agent;

use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\StructuredAnonymousAgent;
use Superwire\Laravel\Runtime\Runner\Agent\Concerns\ProvidesSuperwireProviderOptions;

final class SuperwireStructuredAnonymousAgent extends StructuredAnonymousAgent implements HasProviderOptions
{
    use ProvidesSuperwireProviderOptions;
}
