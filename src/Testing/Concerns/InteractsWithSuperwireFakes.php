<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Testing\Concerns;

use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Superwire\Laravel\Testing\Fakes\ScriptedToolLoopProvider;
use Superwire\Laravel\Testing\Fakes\ToolLoopProvider;

trait InteractsWithSuperwireFakes
{
    /**
     * @param array<string, mixed> $resultsByPrompt
     */
    protected function fakeToolLoopProvider(array $resultsByPrompt): ToolLoopProvider
    {
        $provider = new ToolLoopProvider($resultsByPrompt);

        $this->useFakeProvider($provider);

        return $provider;
    }

    /**
     * @param array<int, callable> $steps
     */
    protected function fakeScriptedToolLoopProvider(array $steps): ScriptedToolLoopProvider
    {
        $provider = new ScriptedToolLoopProvider($steps);

        $this->useFakeProvider($provider);

        return $provider;
    }

    protected function useFakeProvider(TextProvider $provider): TextProvider
    {
        app()->instance(AiManager::class, new class (app(), $provider) extends AiManager {
            public function __construct($app, private readonly TextProvider $provider)
            {
                parent::__construct($app);
            }

            public function textProvider(?string $name = null): TextProvider
            {
                return $this->provider;
            }
        });

        return $provider;
    }
}
