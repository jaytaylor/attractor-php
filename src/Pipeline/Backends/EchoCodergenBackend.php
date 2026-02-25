<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Backends;

use Attractor\Pipeline\CodergenBackend;
use Attractor\Pipeline\Model\Node;
use Attractor\Pipeline\Runtime\Context;

final class EchoCodergenBackend implements CodergenBackend
{
    public function run(Node $node, string $prompt, Context $context)
    {
        return '[echo-backend] ' . $prompt;
    }
}
