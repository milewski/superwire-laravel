<?php

declare(strict_types = 1);

return [
    'runtime' => [
        'fork' => env('SUPERWIRE_RUNTIME_FORK', true),
        'stream' => env('SUPERWIRE_RUNTIME_STREAM', true),
        'executor' => env('SUPERWIRE_WORKFLOW_EXECUTOR', 'serial'),
        'agent_mode' => env('SUPERWIRE_AGENT_MODE', 'request'),
        'output_strategy' => env('SUPERWIRE_OUTPUT_STRATEGY', 'structured'),
        'max_parallel_agents' => env('SUPERWIRE_MAX_PARALLEL_AGENTS', 4),
        'max_agent_request_attempts' => env('SUPERWIRE_MAX_AGENT_REQUEST_ATTEMPTS', 10),
        'max_agent_tool_steps' => env('SUPERWIRE_MAX_AGENT_TOOL_STEPS', 20),
    ],

    'cli' => [
        'path' => env('SUPERWIRE_CLI_PATH', base_path('superwire-cli')),
    ],

    'ai' => [
        'providers' => [],
    ],

    'tools' => [
        'internal_token' => env('SUPERWIRE_INTERNAL_TOOL_TOKEN'),
    ],
];
