<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime;

use InvalidArgumentException;

final readonly class ReferenceResolver
{
    public function __construct(
        private array $inputs,
        private array $secrets,
        private array $agentOutputs,
        private ?string $iterationIdentifier = null,
        private mixed $iterationValue = null,
    )
    {
    }

    public function resolve(string $reference): mixed
    {
        $segments = explode('.', $reference);
        $root = array_shift($segments);

        $value = match ($root) {
            'input' => $this->inputs,
            'secrets' => $this->secrets,
            'agent' => $this->resolveAgent($segments, $reference),
            $this->iterationIdentifier => $this->iterationValue,
            default => throw new InvalidArgumentException(sprintf('Unknown workflow reference `%s`.', $reference)),
        };

        return $this->readPath($value, $segments, $reference);
    }

    private function resolveAgent(array &$segments, string $reference): mixed
    {
        $agentName = array_shift($segments);

        if ($agentName === null || !array_key_exists($agentName, $this->agentOutputs)) {
            throw new InvalidArgumentException(sprintf('Unknown agent reference `%s`.', $reference));
        }

        return $this->agentOutputs[ $agentName ];
    }

    private function readPath(mixed $value, array $segments, string $reference): mixed
    {
        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[ $segment ];

                continue;
            }

            if (is_object($value) && property_exists($value, $segment)) {
                $value = $value->{$segment};

                continue;
            }

            throw new InvalidArgumentException(sprintf('Unable to resolve workflow reference `%s`.', $reference));
        }

        return $value;
    }
}
