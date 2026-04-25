<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Runner\Agent;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Superwire\Laravel\Runtime\Runner\Agent\Concerns\ProvidesSuperwireProviderOptions;

class SuperwireAnonymousAgent extends AnonymousAgent implements HasProviderOptions
{
    use ProvidesSuperwireProviderOptions;
}
