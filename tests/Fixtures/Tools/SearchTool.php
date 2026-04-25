<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Fixtures\Tools;

use Superwire\Laravel\Tools\AbstractTool;

final class SearchTool extends AbstractTool
{
    public array $calls = [];

    public function handle(array $input, array $bounded): array
    {
        $this->calls[] = [ $input, $bounded ];

        return [
            'query' => $input[ 'query' ],
            'tenant' => $bounded[ 'tenant_id' ],
        ];
    }
}
