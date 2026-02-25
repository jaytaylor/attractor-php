<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Handlers;

use Attractor\Pipeline\Handler;
use Attractor\Pipeline\Model\Graph;
use Attractor\Pipeline\Model\Node;
use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;

final class ToolHandler implements Handler
{
    public function execute(Node $node, Context $context, Graph $graph, string $logsRoot): Outcome
    {
        $cmd = (string) $node->attr('command', '');
        if ($cmd === '') {
            return Outcome::fail('tool command missing');
        }

        $proc = proc_open(['/bin/bash', '-lc', $cmd], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return Outcome::fail('failed to start command');
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            return Outcome::fail(trim($stderr !== '' ? $stderr : $stdout));
        }

        return Outcome::success(trim($stdout));
    }
}
