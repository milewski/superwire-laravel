<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Testing\Fakes;

use Laravel\Ai\Gateway\TextGenerationOptions;

final readonly class TextRequest
{
    public function __construct(
        private ?string $instructions,
        private array $messages,
        private array $tools,
        private string $model,
        private ?TextGenerationOptions $options,
    )
    {
    }

    public function systemPrompt(): ?string
    {
        return $this->instructions;
    }

    public function systemPrompts(): array
    {
        return $this->instructions === null ? [] : [ $this->instructions ];
    }

    public function messages(): array
    {
        return $this->messages;
    }

    public function prompt(): ?string
    {
        $last = $this->messages[array_key_last($this->messages)] ?? null;

        return is_object($last) && property_exists($last, 'content') ? $last->content : null;
    }

    public function tools(): array
    {
        return $this->tools;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function options(): ?TextGenerationOptions
    {
        return $this->options;
    }

    public function temperature(): ?float
    {
        return $this->options?->temperature;
    }

    public function maxTokens(): ?int
    {
        return $this->options?->maxTokens;
    }

    public function topP(): ?float
    {
        return null;
    }
}
