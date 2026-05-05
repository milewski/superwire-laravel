<?php

declare(strict_types = 1);

return [
    'executor' => [
        'url' => env('SUPERWIRE_EXECUTOR_URL', 'http://localhost:3000'),
        'timeout' => env('SUPERWIRE_EXECUTOR_TIMEOUT', 300),
    ],
];
