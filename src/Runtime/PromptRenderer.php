<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use Superwire\Laravel\Data\Prompt\Prompt;

final class PromptRenderer
{
    public function render(Prompt $prompt, ReferenceResolver $resolver): string
    {
        if ($prompt->isText()) {
            return (string) $prompt->text;
        }

        $rendered = '';

        foreach ($prompt->templateParts as $part) {
            if ($part->isText()) {
                $rendered .= $part->text;

                continue;
            }

            $value = $resolver->resolve($part->expression->reference);
            $rendered .= is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $rendered;
    }
}
