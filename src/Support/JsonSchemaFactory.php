<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Support;

use InvalidArgumentException;
use Swaggest\JsonSchema\Exception\InvalidValue;
use Swaggest\JsonSchema\Schema;
use Throwable;

final class JsonSchemaFactory
{
    public static function fromArray(array $definition, string $name): Schema
    {
        try {
            /** @var Schema $schema */
            $schema = Schema::import(self::toObject(self::normalizeDefinition(value: $definition)));

            return $schema;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(sprintf('Invalid JSON schema for %s: %s', $name, $exception->getMessage()), previous: $exception);
        }
    }

    public static function validate(Schema $schema, mixed $value, string $name): void
    {
        try {
            $schema->in(self::toObject($value));
        } catch (InvalidValue $exception) {
            throw new InvalidArgumentException(sprintf('Invalid %s: %s', $name, $exception->getMessage()), previous: $exception);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(sprintf('Invalid %s: %s', $name, $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Schema $schema): array
    {
        $json = json_encode($schema, JSON_THROW_ON_ERROR);
        $array = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($array)) {
            throw new InvalidArgumentException('schema must encode to an array');
        }

        return $array;
    }

    private static function toObject(mixed $value): mixed
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);

        return json_decode($json, false, flags: JSON_THROW_ON_ERROR);
    }

    private static function normalizeDefinition(mixed $value, ?string $key = null): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $childKey => $childValue) {
            $normalized[ $childKey ] = self::normalizeDefinition(
                value: $childValue,
                key: is_string($childKey) ? $childKey : null,
            );
        }

        if ($key === 'properties' && $normalized === []) {
            return (object) [];
        }

        return $normalized;
    }
}
