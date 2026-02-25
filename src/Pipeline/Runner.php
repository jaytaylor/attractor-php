<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

use Attractor\Pipeline\Dot\DotParser;
use Attractor\Pipeline\Engine\ConditionEvaluator;
use Attractor\Pipeline\Engine\HandlerResolver;
use Attractor\Pipeline\Model\Edge;
use Attractor\Pipeline\Model\Graph;
use Attractor\Pipeline\Runtime\ArtifactStore;
use Attractor\Pipeline\Runtime\Checkpoint;
use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;
use Attractor\Pipeline\Validation\Validator;

final class Runner
{
    private readonly DotParser $parser;

    public function __construct(
        private readonly HandlerRegistry $handlers,
        private readonly TransformRegistry $transforms,
        private readonly Validator $validator,
        private readonly Interviewer $interviewer,
    ) {
        $this->parser = new DotParser();
    }

    public function parseDot(string $dotSource): Graph
    {
        return $this->transforms->apply($this->parser->parse($dotSource));
    }

    /** @return list<\Attractor\Pipeline\Validation\Diagnostic> */
    public function validate(Graph $graph): array
    {
        return $this->validator->validate($graph);
    }

    public function run(Graph $graph, RunnerConfig $config): PipelineOutcome
    {
        $this->validator->validateOrRaise($graph);

        $store = new ArtifactStore($config->logsRoot);
        $store->ensureRunDir();

        $ctx = new Context([
            'goal' => $graph->goal(),
        ]);

        $current = $graph->startNode();
        if ($current === null) {
            throw new \RuntimeException('no start node');
        }

        $resolver = new HandlerResolver($this->handlers);
        $completed = [];
        $retryCounts = [];
        $goalStatuses = [];

        foreach ($graph->nodes as $node) {
            if (($node->attr('goal_gate') ?? 'false') === 'true') {
                $goalStatuses[$node->id] = false;
            }
        }

        while (true) {
            $handler = $resolver->resolve($current);
            $outcome = $handler->execute($current, $ctx, $graph, $config->logsRoot);

            $ctx->merge($outcome->contextUpdates);
            $completed[] = $current->id;
            if (isset($goalStatuses[$current->id]) && $outcome->status === 'SUCCESS') {
                $goalStatuses[$current->id] = true;
            }

            $statusPayload = [
                'node_id' => $current->id,
                'status' => $outcome->status,
                'message' => $outcome->message,
                'preferred_label' => $outcome->preferredLabel,
                'timestamp' => date(DATE_ATOM),
            ];
            $store->writeStatus($current->id, $statusPayload);

            $store->writeCheckpoint(new Checkpoint(
                currentNode: $current->id,
                completedNodes: $completed,
                context: $ctx->all(),
                retryCounts: $retryCounts,
            ));

            if ($current->shape() === 'Msquare' || preg_match('/^(exit|end)$/i', $current->id) === 1) {
                $allGoalSatisfied = array_reduce($goalStatuses, static fn (bool $carry, bool $ok): bool => $carry && $ok, true);
                if ($allGoalSatisfied) {
                    $store->writeManifest([
                        'status' => 'success',
                        'completed_nodes' => $completed,
                    ]);

                    return new PipelineOutcome('success', $completed, $config->logsRoot);
                }

                $retryTarget = $current->attr('retry_target') ?? $graph->attrs['retry_target'] ?? null;
                if ($retryTarget !== null && isset($graph->nodes[$retryTarget])) {
                    $current = $graph->nodes[$retryTarget];
                    continue;
                }

                $store->writeManifest([
                    'status' => 'fail',
                    'completed_nodes' => $completed,
                    'reason' => 'goal gates unsatisfied',
                ]);

                return new PipelineOutcome('fail', $completed, $config->logsRoot, 'goal gates unsatisfied');
            }

            if (in_array($outcome->status, ['FAIL', 'RETRY'], true)) {
                $maxRetries = (int) ($current->attr('max_retries', '0') ?? '0');
                $retryCounts[$current->id] = ($retryCounts[$current->id] ?? 0) + 1;
                if ($retryCounts[$current->id] <= $maxRetries) {
                    continue;
                }

                $failureEdge = $this->findFailureEdge($graph->outgoing($current->id));
                if ($failureEdge !== null && isset($graph->nodes[$failureEdge->to])) {
                    $current = $graph->nodes[$failureEdge->to];
                    continue;
                }

                $retryTarget = $current->attr('retry_target') ?? $graph->attrs['retry_target'] ?? null;
                if ($retryTarget !== null && isset($graph->nodes[$retryTarget])) {
                    $current = $graph->nodes[$retryTarget];
                    continue;
                }

                $fallback = $current->attr('fallback_retry_target') ?? $graph->attrs['fallback_retry_target'] ?? null;
                if ($fallback !== null && isset($graph->nodes[$fallback])) {
                    $current = $graph->nodes[$fallback];
                    continue;
                }

                $store->writeManifest([
                    'status' => 'fail',
                    'completed_nodes' => $completed,
                    'reason' => 'failure routing exhausted',
                ]);

                return new PipelineOutcome('fail', $completed, $config->logsRoot, 'failure routing exhausted');
            }

            $next = $this->selectNextEdge($graph->outgoing($current->id), $outcome, $ctx, $config->preferredLabel);
            if ($next === null) {
                $store->writeManifest([
                    'status' => 'fail',
                    'completed_nodes' => $completed,
                    'reason' => 'no next edge',
                ]);

                return new PipelineOutcome('fail', $completed, $config->logsRoot, 'no next edge');
            }

            if (($next->attrs['loop_restart'] ?? 'false') === 'true') {
                $freshRoot = rtrim(dirname($config->logsRoot), '/') . '/restart-' . basename($config->logsRoot);
                return $this->run($graph, new RunnerConfig($freshRoot, $config->preferredLabel, $config->autoStatus));
            }

            $current = $graph->nodes[$next->to] ?? throw new \RuntimeException('next node missing: ' . $next->to);
        }
    }

    public function resume(string $logsRoot, RunnerConfig $config, Graph $graph): PipelineOutcome
    {
        $store = new ArtifactStore($logsRoot);
        $checkpoint = $store->readCheckpoint();
        if ($checkpoint === null) {
            throw new \RuntimeException('checkpoint not found');
        }

        if (!isset($graph->nodes[$checkpoint->currentNode])) {
            throw new \RuntimeException('checkpoint node missing from graph');
        }

        // Fidelity downgrade per spec for resumed runs after full context nodes.
        $graph->nodes[$checkpoint->currentNode]->attrs['fidelity'] = 'summary:high';

        return $this->run($graph, $config);
    }

    /** @param list<Edge> $edges */
    private function selectNextEdge(array $edges, Outcome $outcome, Context $context, ?string $preferredLabel = null): ?Edge
    {
        if ($edges === []) {
            return null;
        }

        $conditionMatches = array_values(array_filter($edges, static fn (Edge $edge): bool => ConditionEvaluator::evaluate($edge->condition(), $outcome, $context)));
        if ($conditionMatches === []) {
            return null;
        }

        $preferred = $outcome->preferredLabel ?? $preferredLabel;
        if ($preferred !== null && trim($preferred) !== '') {
            foreach ($conditionMatches as $edge) {
                if ($this->normalizedLabel($edge->label()) === $this->normalizedLabel($preferred)) {
                    return $edge;
                }
            }
        }

        foreach ($outcome->suggestedNodeIds as $id) {
            foreach ($conditionMatches as $edge) {
                if ($edge->to === $id) {
                    return $edge;
                }
            }
        }

        usort($conditionMatches, static function (Edge $a, Edge $b): int {
            $byWeight = $b->weight() <=> $a->weight();
            if ($byWeight !== 0) {
                return $byWeight;
            }

            return strcmp($a->to, $b->to);
        });

        return $conditionMatches[0] ?? null;
    }

    /** @param list<Edge> $edges */
    private function findFailureEdge(array $edges): ?Edge
    {
        foreach ($edges as $edge) {
            if ($this->normalizedLabel($edge->label()) === 'fail') {
                return $edge;
            }
        }

        return null;
    }

    private function normalizedLabel(string $label): string
    {
        $trimmed = trim($label);
        $trimmed = preg_replace('/^\[[A-Za-z]\]\s*/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/^[A-Za-z]\)\s*/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/^[A-Za-z]\s+-\s+/', '', $trimmed) ?? $trimmed;

        return strtolower(trim($trimmed));
    }
}
