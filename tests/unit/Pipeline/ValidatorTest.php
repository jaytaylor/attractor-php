<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\Pipeline;

use Attractor\Pipeline\Dot\DotParser;
use Attractor\Pipeline\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidGraphProducesNoErrors(): void
    {
        $dot = <<<'DOT'
        digraph G {
          start [shape=Mdiamond];
          plan [shape=box, prompt="x"];
          exit [shape=Msquare];
          start -> plan;
          plan -> exit [condition="outcome = SUCCESS"];
        }
        DOT;

        $graph = (new DotParser())->parse($dot);
        $diags = (new Validator())->validate($graph);

        $errors = array_values(array_filter($diags, static fn ($d): bool => $d->severity === 'error'));
        $this->assertSame([], $errors);
    }

    public function testInvalidGraphFindsCriticalErrors(): void
    {
        $dot = <<<'DOT'
        digraph G {
          start [shape=Mdiamond];
          start2 [shape=Mdiamond];
          plan [shape=box];
          start -> plan;
          plan -> missing [condition="badexpr"];
        }
        DOT;

        $graph = (new DotParser())->parse($dot);
        $diags = (new Validator())->validate($graph);

        $rules = array_map(static fn ($d): string => $d->rule, $diags);
        $this->assertContains('start-node-count', $rules);
        $this->assertContains('exit-node-count', $rules);
        $this->assertContains('edge-to-exists', $rules);
        $this->assertContains('condition-parse', $rules);
    }

    public function testValidateOrRaiseThrowsOnErrors(): void
    {
        $dot = <<<'DOT'
        digraph G {
          n1 [shape=box];
          n2 [shape=Msquare];
          n1 -> n2;
        }
        DOT;

        $graph = (new DotParser())->parse($dot);

        $this->expectException(\RuntimeException::class);
        (new Validator())->validateOrRaise($graph);
    }
}
