<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\Pipeline;

use Attractor\Pipeline\Dot\DotParser;
use PHPUnit\Framework\TestCase;

final class DotParserTest extends TestCase
{
    public function testParsesSubsetWithDefaultsAndChainedEdgesAndSubgraphFlattening(): void
    {
        $dot = <<<'DOT'
        digraph G {
          // comments should be stripped
          graph [goal="ship", model_stylesheet="box { model = \"a\"; } .fast { model = \"b\"; } #review { model = \"c\"; }"];
          node [shape=box, timeout="30"];
          edge [weight=1];

          start [shape=Mdiamond];
          subgraph cluster_x {
            plan [class=fast, prompt="do $goal"];
          }
          review [prompt="review"];
          exit [shape=Msquare];

          start -> plan -> review [label="Y) Proceed", condition="outcome = SUCCESS"];
          review -> exit [label="done", weight=7];
        }
        DOT;

        $graph = (new DotParser())->parse($dot);

        $this->assertSame('ship', $graph->goal());
        $this->assertCount(4, $graph->nodes);
        $this->assertCount(3, $graph->edges);
        $this->assertSame('box', $graph->nodes['plan']->shape());
        $this->assertSame('30', $graph->nodes['plan']->attrs['timeout']);

        // class selector overrides shape selector and explicit id selector overrides class
        $this->assertSame('b', $graph->nodes['plan']->attrs['model']);
        $this->assertSame('c', $graph->nodes['review']->attrs['model']);
    }

    public function testQuotedAndUnquotedAttrs(): void
    {
        $dot = <<<'DOT'
        digraph G {
          start [shape=Mdiamond];
          n1 [shape=box, prompt="hello", class=fast];
          exit [shape=Msquare];
          start -> n1 [label=go];
          n1 -> exit;
        }
        DOT;

        $graph = (new DotParser())->parse($dot);
        $this->assertSame('hello', $graph->nodes['n1']->attrs['prompt']);
        $this->assertSame('go', $graph->edges[0]->label());
    }
}
