<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;

final class LaravelAiToolFactory
{
    /**
     * @param Closure(array<string, mixed>): string $handler
     * @param Closure(JsonSchema): array<string, Type> $schema
     */
    public static function make(string $name, string $description, Closure $handler, Closure $schema): LaravelAiTool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(sprintf('Tool name `%s` is not a valid Laravel AI SDK tool class name.', $name));
        }

        $class = sprintf('Superwire\\Laravel\\Tools\\Generated\\%s', $name);

        if (!class_exists($class, false)) {
            eval(sprintf('namespace Superwire\\Laravel\\Tools\\Generated; class %s extends \\Superwire\\Laravel\\Tools\\LaravelAiTool {}', $name));
        }

        return new $class($description, $handler, $schema);
    }
}
