<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime;

use Superwire\Laravel\Contracts\WorkflowCompiler;
use Superwire\Laravel\Data\Prompt\Prompt;
use Superwire\Laravel\Runtime\PromptRenderer;
use Superwire\Laravel\Runtime\ReferenceResolver;
use Superwire\Laravel\Tests\TestCase;

final class PromptRendererTest extends TestCase
{
    public function test_it_returns_plain_text_prompts_without_resolving_references(): void
    {
        $renderer = new PromptRenderer();
        $resolver = new ReferenceResolver(
            inputs: [],
            secrets: [],
            agentOutputs: [],
        );

        $this->assertSame(
            expected: 'Write a short welcome message.',
            actual: $renderer->render(
                prompt: $this->compilePromptFromWire(
                    agentName: 'greeting',
                    wire: <<<WIRE
                        provider openai {
                            driver: "openai"
                            endpoint: "http://example.test/v1"
                            api_key: "test-key"
                            models: ["test-model"]
                        }

                        agent greeting {
                            model: openai("test-model")
                            prompt: "Write a short welcome message."
                            output: string
                        }

                        output {
                            greeting: agent.greeting
                        }
                    WIRE,
                ),
                resolver: $resolver,
            ),
        );
    }

    public function test_it_renders_template_parts_with_resolved_scalar_expressions(): void
    {
        $renderer = new PromptRenderer();
        $resolver = new ReferenceResolver(
            inputs: [
                'product_name' => 'Superwire',
                'audience' => 'developers',
            ],
            secrets: [],
            agentOutputs: [],
        );

        $prompt = $this->compilePromptFromWire(
            agentName: 'summary',
            wire: <<<'WIRE'
                provider openai {
                    driver: "openai"
                    endpoint: "http://example.test/v1"
                    api_key: "test-key"
                    models: ["test-model"]
                }

                input {
                    product_name: string
                    audience: string
                }

                agent summary {
                    model: openai("test-model")
                    prompt: "Summarize {{ input.product_name }} for {{ input.audience }}."
                    output: string
                }

                output {
                    summary: agent.summary
                }
            WIRE,
        );

        $this->assertSame(
            expected: 'Summarize Superwire for developers.',
            actual: $renderer->render(prompt: $prompt, resolver: $resolver),
        );
    }

    public function test_it_json_encodes_non_scalar_expression_values(): void
    {
        $renderer = new PromptRenderer();
        $resolver = new ReferenceResolver(
            inputs: [],
            secrets: [],
            agentOutputs: [
                'summary' => [
                    'points' => [ 'fast', 'typed' ],
                ],
            ],
        );

        $prompt = $this->compilePromptFromWire(
            agentName: 'consumer',
            wire: <<<'WIRE'
            provider openai {
                driver: "openai"
                endpoint: "http://example.test/v1"
                api_key: "test-key"
                models: ["test-model"]
            }

            agent summary {
                model: openai("test-model")
                prompt: "Return points."
                output: {
                    points: [string]
                }
            }

            agent consumer {
                model: openai("test-model")
                prompt: "Use these points: {{ agent.summary.points }}"
                output: string
            }

            output {
                consumer: agent.consumer
            }
            WIRE,
        );

        $this->assertSame(
            expected: 'Use these points: ["fast","typed"]',
            actual: $renderer->render(prompt: $prompt, resolver: $resolver),
        );
    }

    public function test_it_renders_null_expression_values_as_empty_strings(): void
    {
        $renderer = new PromptRenderer();
        $resolver = new ReferenceResolver(
            inputs: [ 'suffix' => null ],
            secrets: [],
            agentOutputs: [],
        );

        $prompt = $this->compilePromptFromWire(
            agentName: 'value',
            wire: <<<'WIRE'
                provider openai {
                    driver: "openai"
                    endpoint: "http://example.test/v1"
                    api_key: "test-key"
                    models: ["test-model"]
                }

                input {
                    suffix: string
                }

                agent value {
                    model: openai("test-model")
                    prompt: "Value:{{ input.suffix }}"
                    output: string
                }

                output {
                    value: agent.value
                }
            WIRE,
        );

        $this->assertSame(
            expected: 'Value:',
            actual: $renderer->render(prompt: $prompt, resolver: $resolver),
        );
    }

    private function compilePromptFromWire(string $agentName, string $wire): Prompt
    {
        $workflowPath = sprintf('%s/superwire-prompt-renderer-%s.wire', sys_get_temp_dir(), uniqid(more_entropy: true));

        file_put_contents(filename: $workflowPath, data: $wire);

        try {

            return $this->app->make(WorkflowCompiler::class)
                ->compile($workflowPath)
                ->agents
                ->findByName($agentName)
                ->prompt;

        } finally {

            if (is_file($workflowPath)) {
                unlink(filename: $workflowPath);
            }

        }
    }
}
