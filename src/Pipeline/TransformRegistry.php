<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

use Attractor\Pipeline\Model\Graph;

final class TransformRegistry
{
    /** @var list<callable(Graph): Graph> */
    private array $transforms = [];

    /** @param callable(Graph): Graph $transform */
    public function register(callable $transform): void
    {
        $this->transforms[] = $transform;
    }

    public function apply(Graph $graph): Graph
    {
        foreach ($this->transforms as $transform) {
            $graph = $transform($graph);
        }

        return $graph;
    }
}
