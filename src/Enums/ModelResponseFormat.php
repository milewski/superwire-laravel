<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Enums;

use InvalidArgumentException;

enum ModelResponseFormat: string
{
    case Auto = 'auto';
    case JsonSchema = 'json_schema';
    case JsonObject = 'json_object';
    case InstructionOnly = 'instruction_only';

    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {

            $responseFormat = self::tryFrom($value);

            if ($responseFormat !== null) {
                return $responseFormat;
            }

        }

        throw new InvalidArgumentException(sprintf(
            'Invalid Superwire executor response format `%s`.',
            is_scalar($value) ? (string) $value : get_debug_type($value),
        ));
    }
}
