<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Data\Workflow\ToolDefinition;
use Superwire\Laravel\Runtime\Tool\ToolInvoker;
use Superwire\Laravel\Tools\AbstractTool;

final readonly class InternalToolController
{
    public function __invoke(Request $request, string $agent, string $tool, WorkflowCompiler $compiler, ToolInvoker $invoker): JsonResponse
    {
        $token = (string) config('superwire.tools.internal_token');

        if ($token === '' || !hash_equals($token, (string) $request->bearerToken())) {
            return new JsonResponse([ 'error' => 'Unauthorized.' ], 401);
        }

        try {

            $workflowPath = $request->string(key: 'workflow_path')->toString();
            $toolClass = $request->string(key: 'tool_class')->toString();
            $bounded = $request->array(key: 'bounded');
            $definition = $this->toolDefinition(compiler: $compiler, workflowPath: $workflowPath, tool: $tool, agent: $agent);
            $toolInstance = $this->toolInstance(toolClass: $toolClass, tool: $tool, agent: $agent, workflowPath: $workflowPath);

            $result = $invoker->invoke(
                tool: $toolInstance,
                definition: $definition,
                input: $request->array(key: 'input'),
                bounded: $bounded,
            );

            return new JsonResponse([ 'result' => $result ]);

        } catch (InvalidArgumentException $exception) {

            return new JsonResponse([ 'error' => $exception->getMessage() ], 422);

        }
    }

    private function toolDefinition(WorkflowCompiler $compiler, string $workflowPath, string $tool, string $agent): ToolDefinition
    {
        if ($workflowPath === '' || !is_file($workflowPath)) {
            throw new InvalidArgumentException(sprintf('Workflow path is not available for agent `%s` tool `%s`.', $agent, $tool));
        }

        $definition = $compiler->compile(workflowPath: $workflowPath)->toolDefinitionNamed(toolName: $tool);

        return $definition ?? throw new InvalidArgumentException(sprintf('Tool `%s` is not defined in workflow `%s`.', $tool, $workflowPath));
    }

    private function toolInstance(string $toolClass, string $tool, string $agent, string $workflowPath): AbstractTool
    {
        if ($toolClass === '' || !is_a($toolClass, AbstractTool::class, true)) {
            throw new InvalidArgumentException(sprintf('Tool `%s` is not available for agent `%s` in workflow `%s`.', $tool, $agent, $workflowPath));
        }

        $toolInstance = app($toolClass);

        if (!$toolInstance instanceof AbstractTool || $toolInstance->name() !== $tool) {
            throw new InvalidArgumentException(sprintf('Tool `%s` is not available for agent `%s` in workflow `%s`.', $tool, $agent, $workflowPath));
        }

        return $toolInstance;
    }
}
