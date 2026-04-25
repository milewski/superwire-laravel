<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Runtime\Tool\ToolInvoker;

final readonly class InternalToolController
{
    public function __invoke(Request $request, string $tool, ToolInvoker $invoker): JsonResponse
    {
        $token = (string) config('superwire.tools.internal_token');

        if ($token === '' || !hash_equals($token, (string) $request->bearerToken())) {
            return new JsonResponse([ 'error' => 'Unauthorized.' ], 401);
        }

        try {

            $definitionPayload = $request->array(key: 'definition');
            $definitionPayload[ 'name' ] = $tool;

            $result = $invoker->invoke(
                definition: ToolDefinition::fromArray($definitionPayload),
                input: $request->array(key: 'input'),
                bounded: $request->array(key: 'bounded'),
            );

            return new JsonResponse([ 'result' => $result ]);

        } catch (InvalidArgumentException $exception) {

            return new JsonResponse([ 'error' => $exception->getMessage() ], 422);

        }
    }
}
