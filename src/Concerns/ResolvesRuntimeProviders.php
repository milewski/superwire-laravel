<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Concerns;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use RuntimeException;
use Superwire\Laravel\Data\Agent\Agent;

trait ResolvesRuntimeProviders
{
    private function intoProvider(string $provider): string
    {
        return match ($provider) {
            'openai', 'ollama', 'anthropic', 'gemini', 'groq', 'xai', 'deepseek', 'mistral' => $provider,
            default => throw new RuntimeException(sprintf('Unknown provider: {%s}', $provider)),
        };
    }

    private function normalizeProviderConfig(array $providerConfig): array
    {
        $normalizedConfig = $this->resolveConfigReferences($providerConfig);

        if (array_key_exists('endpoint', $normalizedConfig)) {

            $normalizedConfig[ 'url' ] = $normalizedConfig[ 'endpoint' ];
            unset($normalizedConfig[ 'endpoint' ]);

        }

        unset($normalizedConfig[ 'driver' ], $normalizedConfig[ 'models' ]);

        return $normalizedConfig;
    }

    private function resolveConfigReferences(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_keys($value) === [ '$ref' ] && is_string($value[ '$ref' ])) {
            return $this->promptParser->resolveReference($value[ '$ref' ], [], [], $this->inputValues, $this->secretValues);
        }

        $resolvedValue = [];

        foreach ($value as $key => $itemValue) {
            $resolvedValue[ $key ] = $this->resolveConfigReferences($itemValue);
        }

        return $resolvedValue;
    }

    private function resolveModel(Agent $agent): string
    {
        if ($agent->model->name !== null) {
            return $agent->model->name;
        }

        if ($agent->model->reference !== null) {

            $resolvedModel = $this->promptParser->resolveReference(
                reference: $agent->model->reference,
                agentOutputs: [],
                inputValues: $this->inputValues,
                secretValues: $this->secretValues,
            );

            if (!is_string($resolvedModel)) {
                throw new RuntimeException(sprintf('Resolved model reference for agent %s must be a string.', $agent->name));
            }

            return $resolvedModel;

        }

        throw new RuntimeException(sprintf('Agent %s does not define a resolvable model.', $agent->name));
    }

    private function shouldStreamResponses(): bool
    {
        return (bool) config('superwire.runtime.stream', true);
    }

    /**
     * @throws CircularDependencyException
     * @throws BindingResolutionException
     */
    private function providerInstance(Agent $agent): TextProvider
    {
        $provider = $this->definition->providers->findByName($agent->provider);
        $providerName = sprintf('superwire_%s', $provider->name);

        config()->set(sprintf('ai.providers.%s', $providerName), [
            ...$this->normalizeProviderConfig($provider->config),
            'driver' => $this->intoProvider($provider->driver),
        ]);

        $textProvider = app(AiManager::class)->textProvider($providerName);

        if (method_exists($textProvider, 'recordProviderConfig')) {
            $textProvider->recordProviderConfig($this->normalizeProviderConfig($provider->config));
        }

        return $textProvider;
    }
}
