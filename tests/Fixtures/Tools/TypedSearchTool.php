<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Fixtures\Tools;

use Superwire\Laravel\Tools\AbstractTool;

final class TypedSearchTool extends AbstractTool
{
    public function handle(SearchInput $input, SearchBounded $bounded): array
    {
        return [
            'query' => $input->query,
            'tenant' => $bounded->tenantId,
        ];
    }
}
