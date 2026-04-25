<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Runtime\Tool;

use InvalidArgumentException;
use Superwire\Laravel\Tools\AbstractTool;

final class ToolRegistry
{
    private array $tools = [];

    public function register(AbstractTool $tool, ?string $name = null): void
    {
        $this->tools[ $name ?? $tool->name() ] = $tool;
    }

    public function get(string $name): AbstractTool
    {
        return $this->tools[ $name ] ?? throw new InvalidArgumentException(sprintf('Tool `%s` is not registered.', $name));
    }
}
