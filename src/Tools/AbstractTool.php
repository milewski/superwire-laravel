<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tools;

use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionNamedType;

abstract class AbstractTool
{
    public function name(): string
    {
        return Str::of(class_basename($this))
            ->beforeLast('Tool')
            ->snake()
            ->toString();
    }

    public function description(): ?string
    {
        return null;
    }

    public function execute(array $input, array $bounded): array
    {
        if (!method_exists($this, 'handle')) {
            throw new InvalidArgumentException(sprintf('Tool `%s` must define a handle method.', $this->name()));
        }

        $method = new ReflectionMethod($this, 'handle');
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {

            $values = str_contains($parameter->getName(), 'bound') ? $bounded : $input;
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->getName() === 'array') {

                $arguments[] = $values;

                continue;

            }

            $arguments[] = $this->hydrate(type: $type->getName(), values: $values);

        }

        $result = $method->invokeArgs($this, $arguments);

        if (!is_array($result)) {
            throw new InvalidArgumentException(sprintf('Tool `%s` handle method must return an array.', $this->name()));
        }

        return $result;
    }

    private function hydrate(string $type, array $values): object
    {
        if (!class_exists($type)) {
            throw new InvalidArgumentException(sprintf('Tool parameter type `%s` does not exist.', $type));
        }

        if (method_exists($type, 'fromArray')) {

            $object = $type::fromArray($values);

            if (is_object($object)) {
                return $object;
            }

        }

        return new $type(...$values);
    }
}
