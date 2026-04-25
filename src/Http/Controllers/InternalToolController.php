<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Superwire\Laravel\Runtime\Tool\ToolScopeRegistry;
use Superwire\Laravel\Runtime\Tool\ToolInvoker;

final readonly class InternalToolController
{
    public function __invoke(Request $request, string $workflow, string $agent, string $tool, ToolScopeRegistry $scopeRegistry, ToolInvoker $invoker): JsonResponse
    {
        $token = (string) config('superwire.tools.internal_token');

        if ($token === '' || !hash_equals($token, (string) $request->bearerToken())) {
            return new JsonResponse([ 'error' => 'Unauthorized.' ], 401);
        }

        try {

            $scopedTool = $scopeRegistry->get(runId: $workflow, agentName: $agent, toolName: $tool);

            $result = $invoker->invoke(
                tool: $scopedTool->tool,
                definition: $scopedTool->binding->definition,
                input: $request->array(key: 'input'),
                bounded: $scopedTool->binding->bounded,
            );

            return new JsonResponse([ 'result' => $result ]);

        } catch (InvalidArgumentException $exception) {

            return new JsonResponse([ 'error' => $exception->getMessage() ], 422);

        }
    }
}
