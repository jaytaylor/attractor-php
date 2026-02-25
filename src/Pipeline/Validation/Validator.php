<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Validation;

use Attractor\Pipeline\Engine\ConditionEvaluator;
use Attractor\Pipeline\Model\Graph;

final class Validator
{
    /** @return list<Diagnostic> */
    public function validate(Graph $graph): array
    {
        $diags = [];

        $starts = array_values(array_filter($graph->nodes, static fn ($node): bool => $node->shape() === 'Mdiamond'));
        if (count($starts) !== 1) {
            $diags[] = new Diagnostic('start-node-count', 'error', 'graph', 'exactly one start node is required');
        }

        $exits = $graph->exitNodes();
        if (count($exits) !== 1) {
            $diags[] = new Diagnostic('exit-node-count', 'error', 'graph', 'exactly one exit node is required');
        }

        $start = $graph->startNode();
        if ($start !== null) {
            if ($graph->incoming($start->id) !== []) {
                $diags[] = new Diagnostic('start-incoming', 'error', $start->id, 'start node must not have incoming edges');
            }

            $reachable = $this->reachableFrom($graph, $start->id);
            foreach (array_keys($graph->nodes) as $nodeId) {
                if (!in_array($nodeId, $reachable, true)) {
                    $diags[] = new Diagnostic('node-reachable', 'error', $nodeId, 'node is unreachable from start');
                }
            }
        }

        foreach ($graph->exitNodes() as $exit) {
            if ($graph->outgoing($exit->id) !== []) {
                $diags[] = new Diagnostic('exit-outgoing', 'error', $exit->id, 'exit node must not have outgoing edges');
            }
        }

        foreach ($graph->edges as $idx => $edge) {
            if (!isset($graph->nodes[$edge->from])) {
                $diags[] = new Diagnostic('edge-from-exists', 'error', 'edge:' . $idx, "edge source '{$edge->from}' does not exist");
            }
            if (!isset($graph->nodes[$edge->to])) {
                $diags[] = new Diagnostic('edge-to-exists', 'error', 'edge:' . $idx, "edge target '{$edge->to}' does not exist");
            }
            if ($edge->condition() !== '') {
                try {
                    ConditionEvaluator::validate($edge->condition());
                } catch (\Throwable $t) {
                    $diags[] = new Diagnostic('condition-parse', 'error', 'edge:' . $idx, $t->getMessage());
                }
            }
        }

        foreach ($graph->nodes as $node) {
            if ($node->shape() === 'box' && trim((string) $node->attr('prompt', '')) === '') {
                $diags[] = new Diagnostic('codergen-prompt', 'warning', $node->id, 'codergen node should define non-empty prompt');
            }
        }

        return $diags;
    }

    public function validateOrRaise(Graph $graph): void
    {
        $errors = array_filter($this->validate($graph), static fn (Diagnostic $d): bool => $d->severity === 'error');
        if ($errors !== []) {
            $messages = implode('; ', array_map(static fn (Diagnostic $d): string => "{$d->rule}:{$d->targetId}:{$d->message}", $errors));
            throw new \RuntimeException('validation failed: ' . $messages);
        }
    }

    /** @return list<string> */
    private function reachableFrom(Graph $graph, string $startId): array
    {
        $queue = [$startId];
        $seen = [];

        while ($queue !== []) {
            $cur = array_shift($queue);
            if (in_array($cur, $seen, true)) {
                continue;
            }
            $seen[] = $cur;

            foreach ($graph->outgoing($cur) as $edge) {
                $queue[] = $edge->to;
            }
        }

        return $seen;
    }
}
