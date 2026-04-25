<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Fixtures\Tools;

use Superwire\Laravel\Tools\AbstractTool;

final class RetryWeatherTool extends AbstractTool
{
    public function name(): string
    {
        return 'retry_weather_tool';
    }

    public function handle(array $input, array $bounded): array
    {
        return [ 'input' => $input, 'bounded' => $bounded ];
    }
}
