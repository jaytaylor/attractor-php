<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

use Attractor\Pipeline\Model\Graph;
use Attractor\Pipeline\Model\Node;
use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;

interface Handler
{
    public function execute(Node $node, Context $context, Graph $graph, string $logsRoot): Outcome;
}
