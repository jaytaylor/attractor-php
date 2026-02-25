<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Handlers;

use Attractor\Pipeline\CodergenBackend;
use Attractor\Pipeline\Handler;
use Attractor\Pipeline\Model\Graph;
use Attractor\Pipeline\Model\Node;
use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;

final class CodergenHandler implements Handler
{
    public function __construct(private readonly CodergenBackend $backend)
    {
    }

    public function execute(Node $node, Context $context, Graph $graph, string $logsRoot): Outcome
    {
        $promptTemplate = (string) $node->attr('prompt', '');
        $prompt = str_replace('$goal', $graph->goal(), $promptTemplate);

        $nodeDir = $logsRoot . '/' . $node->id;
        if (!is_dir($nodeDir)) {
            mkdir($nodeDir, 0777, true);
        }
        file_put_contents($nodeDir . '/prompt.md', $prompt);

        $result = $this->backend->run($node, $prompt, $context);
        if ($result instanceof Outcome) {
            file_put_contents($nodeDir . '/response.md', $result->message);
            return $result;
        }

        file_put_contents($nodeDir . '/response.md', (string) $result);
        return Outcome::success((string) $result);
    }
}
