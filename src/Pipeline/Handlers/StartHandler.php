<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Handlers;

use Attractor\Pipeline\Handler;
use Attractor\Pipeline\Model\Graph;
use Attractor\Pipeline\Model\Node;
use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;

final class StartHandler implements Handler
{
    public function execute(Node $node, Context $context, Graph $graph, string $logsRoot): Outcome
    {
        return Outcome::success('start');
    }
}
