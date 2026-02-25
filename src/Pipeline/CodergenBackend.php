<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

use Attractor\Pipeline\Model\Node;
use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;

interface CodergenBackend
{
    /** @return string|Outcome */
    public function run(Node $node, string $prompt, Context $context);
}
