<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Transforms;

use Attractor\Pipeline\Model\Graph;

final class VariableExpansionTransform
{
    public function __invoke(Graph $graph): Graph
    {
        $goal = $graph->goal();
        foreach ($graph->nodes as $node) {
            if (!isset($node->attrs['prompt'])) {
                continue;
            }

            $node->attrs['prompt'] = str_replace('$goal', $goal, $node->attrs['prompt']);
        }

        return $graph;
    }
}
