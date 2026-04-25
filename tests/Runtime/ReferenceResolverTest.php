<?php

declare(strict_types = 1);

namespace Superwire\Laravel\Tests\Runtime;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Superwire\Laravel\Runtime\ReferenceResolver;

final class ReferenceResolverTest extends TestCase
{
    public function test_it_resolves_input_secret_agent_and_iteration_references(): void
    {
        $resolver = new ReferenceResolver(
            inputs: [
                'product' => [
                    'name' => 'Superwire',
                ],
            ],
            secrets: [
                'api_key' => 'test-key',
            ],
            agentOutputs: [
                'summary' => [
                    'tagline' => 'Ship it',
                ],
            ],
            iterationIdentifier: 'item',
            iterationValue: (object) [
                'name' => 'First item',
            ],
        );

        $this->assertSame('Superwire', $resolver->resolve('input.product.name'));
        $this->assertSame('test-key', $resolver->resolve('secrets.api_key'));
        $this->assertSame('Ship it', $resolver->resolve('agent.summary.tagline'));
        $this->assertSame('First item', $resolver->resolve('item.name'));
    }

    public function test_it_returns_root_values_when_no_nested_path_is_requested(): void
    {
        $resolver = new ReferenceResolver(
            inputs: [ 'topic' => 'release' ],
            secrets: [ 'api_key' => 'test-key' ],
            agentOutputs: [ 'draft' => 'hello' ],
        );

        $this->assertSame([ 'topic' => 'release' ], $resolver->resolve(reference: 'input'));
        $this->assertSame('hello', $resolver->resolve(reference: 'agent.draft'));
    }

    public function test_it_rejects_unknown_reference_roots(): void
    {
        $resolver = new ReferenceResolver(
            inputs: [],
            secrets: [],
            agentOutputs: [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown workflow reference `missing.value`.');

        $resolver->resolve(reference: 'missing.value');
    }

    public function test_it_rejects_unknown_agent_references(): void
    {
        $resolver = new ReferenceResolver(
            inputs: [],
            secrets: [],
            agentOutputs: [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown agent reference `agent.missing`.');

        $resolver->resolve(reference: 'agent.missing');
    }

    public function test_it_rejects_unresolvable_nested_paths(): void
    {
        $resolver = new ReferenceResolver(
            inputs: [ 'product' => [ 'name' => 'Superwire' ] ],
            secrets: [],
            agentOutputs: [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to resolve workflow reference `input.product.slug`.');

        $resolver->resolve(reference: 'input.product.slug');
    }
}
