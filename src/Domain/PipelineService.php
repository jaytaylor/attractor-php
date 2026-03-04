<?php

declare(strict_types=1);

namespace AttractorPhp\Domain;

use AttractorPhp\Http\ApiError;
use AttractorPhp\Llm\TaskLlmService;
use AttractorPhp\Storage\RunStore;

final class PipelineService
{
    public function __construct(
        private readonly RunStore $store,
        private readonly DotService $dotService,
        private readonly DotGraphParser $graphParser,
        private readonly TaskLlmService $taskLlmService
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function listRuns(bool $includeArchived = false): array
    {
        $this->tickAll();
        return $this->store->listRuns($includeArchived);
    }

    /** @return array<string,mixed> */
    public function getRun(string $runId): array
    {
        $this->tickRun($runId);
        return $this->store->getRun($runId);
    }

    /**
      * @param array<string,mixed> $input
      * @return array<string,mixed>
      */
    public function create(array $input): array
    {
        $dotSource = (string) ($input['dotSource'] ?? '');
        if (trim($dotSource) === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'dotSource is required');
        }

        $validation = $this->dotService->validate($dotSource);
        if (!$validation['valid']) {
            throw new ApiError(400, 'BAD_REQUEST', 'invalid DOT source');
        }

        $input['dotSource'] = (string) $validation['dotSource'];
        $graph = $this->graphParser->parse((string) $input['dotSource']);

        $run = $this->store->createRun($input);
        $runId = (string) $run['id'];
        $startNodeId = (string) ($graph['startNodeId'] ?? '');

        if ($startNodeId === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'unable to resolve start node');
        }

        $run['status'] = 'running';
        $run['currentNodeId'] = $startNodeId;
        $run['stages'] = [];
        $this->setStageStatus($run, $startNodeId, 'running');
        $run['_runtime'] = [
            'mode' => 'graph',
            'graph' => $graph,
            'currentNodeId' => $startNodeId,
            'step' => 0,
            'lastNodeId' => null,
            'lastNodeOutput' => '',
            'lastValidation' => '',
            'waitingQuestionId' => null,
            'waitingNodeId' => null,
            'llmOptions' => $this->normalizeLlmOptions($input),
        ];

        $this->store->saveRun($runId, $run);
        $this->store->emitEvent($runId, 'PipelineStarted', ['nodeId' => $startNodeId]);
        $this->store->emitEvent($runId, 'StageStarted', ['nodeId' => $startNodeId]);
        $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => $startNodeId]);
        $this->store->saveCheckpoint($runId, [
            'current_node' => $startNodeId,
            'completed_nodes' => ['start'],
            'timestamp' => gmdate('c'),
        ]);

        $this->writeArtifacts($runId, 'running');
        return $this->store->getRun($runId);
    }

    /** @return array<string,mixed> */
    public function cancel(string $runId): array
    {
        $this->tickRun($runId);
        $run = $this->store->getRun($runId);
        if ((string) ($run['status'] ?? '') !== 'running') {
            throw new ApiError(409, 'INVALID_STATE', 'only running runs can be cancelled');
        }

        $run['status'] = 'cancelled';
        $run['finishedAtMs'] = (int) floor(microtime(true) * 1000);
        unset($run['_runtime']);
        $this->store->saveRun($runId, $run);
        $this->store->emitEvent($runId, 'PipelineFailed', ['reason' => 'cancelled']);
        $this->store->saveCheckpoint($runId, [
            'current_node' => (string) ($run['currentNodeId'] ?? 'unknown'),
            'completed_nodes' => $this->completedNodes($run),
            'timestamp' => gmdate('c'),
        ]);
        return $this->store->getRun($runId);
    }

    public function delete(string $runId): void
    {
        $this->tickRun($runId);
        $run = $this->store->getRun($runId);
        if ((string) ($run['status'] ?? '') === 'running') {
            throw new ApiError(409, 'INVALID_STATE', 'cannot delete running run');
        }

        $this->deleteDir($this->store->runDir($runId));
    }

    /** @return array<string,mixed> */
    public function setArchived(string $runId, bool $archived): array
    {
        $this->tickRun($runId);
        $run = $this->store->getRun($runId);
        $status = (string) ($run['status'] ?? '');
        if (!$this->isTerminalStatus($status)) {
            throw new ApiError(409, 'INVALID_STATE', 'only terminal runs can be archived/unarchived');
        }

        $currentlyArchived = (bool) ($run['archived'] ?? false);
        if ($archived && $currentlyArchived) {
            throw new ApiError(409, 'INVALID_STATE', 'run is already archived');
        }

        if (!$archived && !$currentlyArchived) {
            throw new ApiError(409, 'INVALID_STATE', 'run is not archived');
        }

        $run['archived'] = $archived;
        $this->store->saveRun($runId, $run);
        return $run;
    }

    /** @return array<string,mixed> */
    public function answerQuestion(string $runId, string $questionId, string $answerKey): array
    {
        $run = $this->store->getRun($runId);
        if ((string) ($run['status'] ?? '') !== 'waiting_human') {
            throw new ApiError(409, 'INVALID_STATE', 'run is not waiting for human input');
        }

        $questions = $this->store->getQuestions($runId);
        $question = null;
        foreach ($questions as $item) {
            if ((string) ($item['id'] ?? '') === $questionId) {
                $question = $item;
                break;
            }
        }

        if ($question === null) {
            throw new ApiError(404, 'NOT_FOUND', 'question not found');
        }

        $targetNodeId = '';
        foreach ((array) ($question['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            if ((string) ($option['key'] ?? '') !== $answerKey) {
                continue;
            }
            $targetNodeId = (string) ($option['targetNodeId'] ?? '');
            break;
        }

        if ($targetNodeId === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'invalid answer option');
        }

        $runtime = $run['_runtime'] ?? [];
        if (!is_array($runtime)) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'runtime state missing for waiting run');
        }
        $waitingNodeId = (string) ($runtime['waitingNodeId'] ?? '');
        if ($waitingNodeId === '') {
            $waitingNodeId = (string) ($run['currentNodeId'] ?? '');
        }

        $this->store->saveQuestions($runId, []);
        $this->setStageStatus($run, $waitingNodeId, 'completed');
        $this->setStageStatus($run, $targetNodeId, 'running');
        $run['status'] = 'running';
        $run['currentNodeId'] = $targetNodeId;

        $runtime['currentNodeId'] = $targetNodeId;
        $runtime['waitingQuestionId'] = null;
        $runtime['waitingNodeId'] = null;
        $runtime['lastValidation'] = strtolower((string) $answerKey) === 'f' ? 'fail' : 'pass';
        $run['_runtime'] = $runtime;

        $this->store->saveRun($runId, $run);
        $this->store->emitEvent($runId, 'InterviewCompleted', ['questionId' => $questionId, 'answer' => $answerKey]);
        $this->store->emitEvent($runId, 'StageCompleted', ['nodeId' => $waitingNodeId]);
        $this->store->emitEvent($runId, 'StageStarted', ['nodeId' => $targetNodeId]);
        $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => $targetNodeId]);
        $this->store->saveCheckpoint($runId, [
            'current_node' => $targetNodeId,
            'completed_nodes' => $this->completedNodes($run),
            'timestamp' => gmdate('c'),
        ]);
        $this->writeArtifacts($runId, 'running-after-human');

        return $this->store->getRun($runId);
    }

    /** @return array<string,mixed> */
    public function iterateRun(string $runId, string $dotSource, string $originalPrompt): array
    {
        $this->tickRun($runId);
        $source = $this->store->getRun($runId);
        if ((string) ($source['status'] ?? '') === 'running') {
            throw new ApiError(409, 'INVALID_STATE', 'cannot iterate a running run');
        }

        $familyId = (string) ($source['familyId'] ?? $source['id']);

        $new = $this->create([
            'dotSource' => $dotSource,
            'fileName' => (string) ($source['fileName'] ?? ''),
            'displayName' => (string) ($source['displayName'] ?? ''),
            'autoApprove' => (bool) ($source['autoApprove'] ?? true),
            'familyId' => $familyId,
            'originalPrompt' => $originalPrompt,
            'provider' => (string) ($source['provider'] ?? ''),
            'model' => (string) ($source['model'] ?? ''),
        ]);

        return ['newId' => $new['id']];
    }

    public function graphSvg(string $runId): string
    {
        $this->tickRun($runId);
        $run = $this->store->getRun($runId);
        return $this->dotService->render((string) ($run['dotSource'] ?? 'digraph empty {}'));
    }

    public function tickAll(): void
    {
        foreach ($this->store->listRuns(true) as $run) {
            if ((string) ($run['status'] ?? '') !== 'running') {
                continue;
            }
            $this->tickRun((string) $run['id']);
        }
    }

    public function tickRun(string $runId): void
    {
        $run = $this->store->getRun($runId);
        if ((string) ($run['status'] ?? '') !== 'running') {
            return;
        }

        $runtime = $run['_runtime'] ?? null;
        if (!is_array($runtime) || (string) ($runtime['mode'] ?? '') !== 'graph') {
            return;
        }

        $graph = $runtime['graph'] ?? [];
        if (!is_array($graph)) {
            $this->failRun($runId, $run, 'runtime graph is missing');
            return;
        }

        $nodeId = (string) ($runtime['currentNodeId'] ?? $run['currentNodeId'] ?? '');
        $nodes = $graph['nodes'] ?? [];
        if (!is_array($nodes) || $nodeId === '' || !isset($nodes[$nodeId]) || !is_array($nodes[$nodeId])) {
            $this->failRun($runId, $run, 'current node is missing from runtime graph');
            return;
        }

        $node = $nodes[$nodeId];
        $outgoing = $graph['outgoing'][$nodeId] ?? [];
        if (!is_array($outgoing)) {
            $outgoing = [];
        }

        try {
            $stepResult = $this->executeNode($runId, $run, $runtime, $node, $outgoing);
        } catch (\Throwable $error) {
            $this->failRun($runId, $run, 'node execution failed: ' . $error->getMessage());
            return;
        }
        if ((bool) ($stepResult['pause'] ?? false)) {
            return;
        }
        if ((bool) ($stepResult['failed'] ?? false)) {
            $this->failRun($runId, $run, (string) ($stepResult['error'] ?? 'node execution failed'));
            return;
        }

        $routeLabel = (string) ($stepResult['routeLabel'] ?? '');
        $nextEdge = $this->selectNextEdge($outgoing, $routeLabel);

        $run = $this->store->getRun($runId);
        if ((string) ($run['status'] ?? '') !== 'running') {
            return;
        }
        $runtime = is_array($run['_runtime'] ?? null) ? $run['_runtime'] : $runtime;

        $this->setStageStatus($run, $nodeId, 'completed');
        $this->store->emitEvent($runId, 'StageCompleted', ['nodeId' => $nodeId]);

        $output = (string) ($stepResult['output'] ?? '');
        if ($output !== '') {
            $runtime['lastNodeOutput'] = $output;
        }
        $runtime['lastNodeId'] = $nodeId;
        $runtime['step'] = (int) ($runtime['step'] ?? 0) + 1;

        if ($routeLabel !== '') {
            $runtime['lastValidation'] = strtolower($routeLabel);
        }

        if ($nextEdge === null) {
            $run['status'] = 'completed';
            $run['currentNodeId'] = $nodeId;
            $run['finishedAtMs'] = (int) floor(microtime(true) * 1000);
            unset($run['_runtime']);
            $this->store->saveRun($runId, $run);
            $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => $nodeId]);
            $this->store->emitEvent($runId, 'PipelineCompleted', ['status' => 'completed']);
            $this->store->saveCheckpoint($runId, [
                'current_node' => $nodeId,
                'completed_nodes' => $this->completedNodes($run),
                'timestamp' => gmdate('c'),
            ]);
            $this->writeArtifacts($runId, 'completed');
            return;
        }

        $nextNodeId = (string) ($nextEdge['to'] ?? '');
        if ($nextNodeId === '') {
            $this->failRun($runId, $run, 'next edge is missing target node');
            return;
        }

        $runtime['currentNodeId'] = $nextNodeId;
        $run['_runtime'] = $runtime;
        $run['currentNodeId'] = $nextNodeId;
        $this->setStageStatus($run, $nextNodeId, 'running');
        $this->store->saveRun($runId, $run);
        $this->store->emitEvent($runId, 'StageStarted', ['nodeId' => $nextNodeId]);
        $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => $nextNodeId]);
        $this->store->saveCheckpoint($runId, [
            'current_node' => $nextNodeId,
            'completed_nodes' => $this->completedNodes($run),
            'timestamp' => gmdate('c'),
        ]);
        $this->writeArtifacts($runId, 'running');
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $runtime
     * @param array<string,mixed> $node
     * @param list<array<string,mixed>> $outgoing
     * @return array<string,mixed>
     */
    private function executeNode(string $runId, array &$run, array &$runtime, array $node, array $outgoing): array
    {
        $nodeId = (string) ($node['id'] ?? '');
        $shape = strtolower((string) ($node['shape'] ?? 'box'));
        $label = trim((string) ($node['label'] ?? $nodeId));
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        if ($nodeId === '' || $this->isStartNode($nodeId, $shape)) {
            return ['routeLabel' => '', 'output' => 'start'];
        }

        if ($this->isHumanNode($shape, $nodeId, $label)) {
            $autoApprove = (bool) ($run['autoApprove'] ?? false);
            if ($autoApprove) {
                return ['routeLabel' => $this->autoApproveRouteLabel($outgoing), 'output' => 'auto-approved'];
            }

            $options = [];
            $keys = range('A', 'Z');
            $idx = 0;
            foreach ($outgoing as $edge) {
                if (!is_array($edge)) {
                    continue;
                }
                $target = (string) ($edge['to'] ?? '');
                if ($target === '') {
                    continue;
                }
                $key = $keys[$idx] ?? ('K' . (string) $idx);
                $edgeLabel = trim((string) ($edge['label'] ?? ''));
                if ($edgeLabel === '') {
                    $edgeLabel = $target;
                }
                $options[] = [
                    'key' => $key,
                    'label' => $edgeLabel,
                    'targetNodeId' => $target,
                ];
                $idx++;
            }

            if ($options === []) {
                return ['failed' => true, 'error' => 'human gate has no outgoing options'];
            }

            $questionId = 'q-' . bin2hex(random_bytes(4));
            $this->store->saveQuestions($runId, [[
                'id' => $questionId,
                'stage' => $nodeId,
                'type' => 'MULTIPLE_CHOICE',
                'text' => $label !== '' ? $label : 'Select next action',
                'options' => $options,
            ]]);

            $this->setStageStatus($run, $nodeId, 'waiting_human');
            $run['status'] = 'waiting_human';
            $runtime['waitingQuestionId'] = $questionId;
            $runtime['waitingNodeId'] = $nodeId;
            $runtime['currentNodeId'] = $nodeId;
            $run['_runtime'] = $runtime;
            $run['currentNodeId'] = $nodeId;
            $this->store->saveRun($runId, $run);
            $this->store->emitEvent($runId, 'InterviewStarted', ['questionId' => $questionId, 'nodeId' => $nodeId]);
            $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => $nodeId]);
            $this->store->saveCheckpoint($runId, [
                'current_node' => $nodeId,
                'completed_nodes' => $this->completedNodes($run),
                'timestamp' => gmdate('c'),
            ]);
            $this->writeArtifacts($runId, 'waiting_human');
            return ['pause' => true];
        }

        if ($this->isToolNode($shape, $attrs)) {
            $command = trim((string) ($attrs['cmd'] ?? $attrs['command'] ?? $attrs['tool'] ?? $label));
            if ($command === '') {
                return ['failed' => true, 'error' => 'tool node missing command'];
            }

            $tool = $this->executeCommand($command, $this->store->runDir($runId));
            $content = "command={$command}\nexitCode={$tool['exitCode']}\n\nstdout:\n{$tool['stdout']}\n\nstderr:\n{$tool['stderr']}\n";
            $this->writeNodeArtifact($runId, $runtime, $nodeId, 'tool', $content);
            $this->applyNodeContext($runId, $nodeId, $content, $tool['exitCode'] === 0 ? 'ok' : 'failed');

            return [
                'routeLabel' => $tool['exitCode'] === 0 ? 'pass' : 'fail',
                'output' => $content,
                'failed' => $tool['exitCode'] !== 0,
                'error' => $tool['exitCode'] === 0 ? '' : 'tool command failed',
            ];
        }

        if ($this->isValidationNode($shape, $nodeId, $label)) {
            $result = $this->validateWithLlm($runId, $runtime, $nodeId, $label, $run);
            return $result;
        }

        $taskOutput = $this->runCodergenNode($runId, $runtime, $nodeId, $label, $run);
        return [
            'routeLabel' => 'pass',
            'output' => $taskOutput,
        ];
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function validateWithLlm(string $runId, array $runtime, string $nodeId, string $label, array $run): array
    {
        $lastOutput = trim((string) ($runtime['lastNodeOutput'] ?? ''));
        if ($lastOutput === '') {
            $lastOutput = '(no prior node output available)';
        }

        $goal = trim((string) ($run['originalPrompt'] ?? ''));
        if ($goal === '') {
            $goal = 'Ensure the workflow output satisfies the pipeline requirements.';
        }

        $systemPrompt = implode("\n", [
            'You are a strict pipeline validator.',
            'Assess whether prior node output satisfies the validation objective.',
            'Reply with PASS or FAIL as the first token, then one short reason.',
        ]);
        $userPrompt = "Validation node: {$nodeId}\nValidation objective: {$label}\nWorkflow goal: {$goal}\n\nCandidate output:\n{$lastOutput}";

        try {
            $text = $this->taskLlmService->completeTask($systemPrompt, $userPrompt, $this->runtimeLlmOptions($run));
        } catch (ApiError $error) {
            return ['failed' => true, 'error' => $error->getMessage()];
        }

        $normalized = strtoupper(trim($text));
        $route = str_starts_with($normalized, 'FAIL') ? 'fail' : 'pass';
        $content = "validatorNode={$nodeId}\nroute={$route}\n\n{$text}\n";
        $this->writeNodeArtifact($runId, $runtime, $nodeId, 'validate', $content);
        $this->applyNodeContext($runId, $nodeId, $text, $route);

        return [
            'routeLabel' => $route,
            'output' => $text,
        ];
    }

    /** @param array<string,mixed> $runtime
      * @param array<string,mixed> $run
      */
    private function runCodergenNode(string $runId, array $runtime, string $nodeId, string $label, array $run): string
    {
        $goal = trim((string) ($run['originalPrompt'] ?? ''));
        if ($goal === '') {
            $goal = 'Execute the current pipeline node and produce a concrete result.';
        }

        $previous = trim((string) ($runtime['lastNodeOutput'] ?? ''));
        if ($previous === '') {
            $previous = '(no prior output)';
        }

        $systemPrompt = implode("\n", [
            'You are executing one node of a software-factory pipeline.',
            'Return concise, concrete output for this node only.',
            'Do not include markdown fences.',
        ]);
        $userPrompt = "Node ID: {$nodeId}\nNode label: {$label}\nWorkflow goal: {$goal}\n\nPrevious node output:\n{$previous}";

        $text = $this->taskLlmService->completeTask($systemPrompt, $userPrompt, $this->runtimeLlmOptions($run));
        $this->writeNodeArtifact($runId, $runtime, $nodeId, 'codergen', $text . "\n");
        $this->applyNodeContext($runId, $nodeId, $text, 'ok');
        return $text;
    }

    /**
      * @param list<array<string,mixed>> $outgoing
      * @return array<string,mixed>|null
      */
    private function selectNextEdge(array $outgoing, string $routeLabel): ?array
    {
        if ($outgoing === []) {
            return null;
        }

        $route = strtolower(trim($routeLabel));
        if ($route !== '') {
            foreach ($outgoing as $edge) {
                if (!is_array($edge)) {
                    continue;
                }
                $label = strtolower(trim((string) ($edge['label'] ?? '')));
                if ($label === $route) {
                    return $edge;
                }
            }
        }

        if (count($outgoing) === 1) {
            return $outgoing[0];
        }

        foreach ($outgoing as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            if (trim((string) ($edge['label'] ?? '')) === '') {
                return $edge;
            }
        }

        return $outgoing[0] ?? null;
    }

    /** @param array<string,mixed> $run */
    private function failRun(string $runId, array $run, string $reason): void
    {
        $run['status'] = 'failed';
        $run['finishedAtMs'] = (int) floor(microtime(true) * 1000);
        unset($run['_runtime']);
        $this->store->saveRun($runId, $run);
        $this->store->emitEvent($runId, 'PipelineFailed', ['reason' => $reason]);
        $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => (string) ($run['currentNodeId'] ?? 'unknown')]);
        $this->store->saveCheckpoint($runId, [
            'current_node' => (string) ($run['currentNodeId'] ?? 'unknown'),
            'completed_nodes' => $this->completedNodes($run),
            'timestamp' => gmdate('c'),
        ]);
        $this->writeArtifacts($runId, 'failed');
    }

    /** @param array<string,mixed> $run */
    private function runtimeLlmOptions(array $run): array
    {
        $runtime = is_array($run['_runtime'] ?? null) ? $run['_runtime'] : [];
        $opts = is_array($runtime['llmOptions'] ?? null) ? $runtime['llmOptions'] : [];
        $provider = trim((string) ($opts['provider'] ?? $run['provider'] ?? ''));
        $model = trim((string) ($opts['model'] ?? $run['model'] ?? ''));
        $result = [];
        if ($provider !== '') {
            $result['provider'] = $provider;
        }
        if ($model !== '') {
            $result['model'] = $model;
        }
        return $result;
    }

    /** @param array<string,mixed> $input
      * @return array<string,mixed>
      */
    private function normalizeLlmOptions(array $input): array
    {
        $result = [];
        $provider = strtolower(trim((string) ($input['provider'] ?? '')));
        $model = trim((string) ($input['model'] ?? ''));
        if ($provider !== '') {
            $result['provider'] = $provider;
        }
        if ($model !== '') {
            $result['model'] = $model;
        }
        return $result;
    }

    /** @param array<string,mixed> $runtime */
    private function writeNodeArtifact(string $runId, array $runtime, string $nodeId, string $kind, string $content): void
    {
        $dir = $this->store->runDir($runId) . '/artifacts/nodes';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $step = (int) ($runtime['step'] ?? 0);
        $safeNode = preg_replace('/[^A-Za-z0-9_-]+/', '-', $nodeId) ?? $nodeId;
        $file = sprintf('%03d-%s-%s.txt', $step, $safeNode, $kind);
        file_put_contents($dir . '/' . $file, $content);
    }

    private function applyNodeContext(string $runId, string $nodeId, string $output, string $status): void
    {
        $context = $this->store->readContext($runId);
        $context['node.' . $nodeId . '.output'] = $output;
        $context['node.' . $nodeId . '.status'] = $status;
        $context['last.nodeId'] = $nodeId;
        $context['last.output'] = $output;
        $context['last.status'] = $status;
        $this->store->saveContext($runId, $context);
    }

    /** @return array{stdout:string,stderr:string,exitCode:int} */
    private function executeCommand(string $command, string $workingDir): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(['/bin/bash', '-lc', $command], $descriptors, $pipes, $workingDir);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'unable to start command', 'exitCode' => 127];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => (int) $exitCode];
    }

    private function isStartNode(string $nodeId, string $shape): bool
    {
        if ($shape === 'mdiamond') {
            return true;
        }
        return strtolower($nodeId) === 'start';
    }

    private function isHumanNode(string $shape, string $nodeId, string $label): bool
    {
        if ($shape === 'hexagon') {
            return true;
        }
        $text = strtolower($nodeId . ' ' . $label);
        return str_contains($text, 'review_gate') || str_contains($text, 'human') || str_contains($text, 'approve');
    }

    /** @param array<string,string> $attrs */
    private function isToolNode(string $shape, array $attrs): bool
    {
        if ($shape === 'parallelogram') {
            return true;
        }
        return isset($attrs['cmd']) || isset($attrs['command']) || isset($attrs['tool']);
    }

    private function isValidationNode(string $shape, string $nodeId, string $label): bool
    {
        if ($shape === 'diamond') {
            return true;
        }
        $text = strtolower($nodeId . ' ' . $label);
        return str_contains($text, 'validate') || str_contains($text, 'verification') || str_contains($text, 'quality gate');
    }

    /** @param list<array<string,mixed>> $outgoing */
    private function autoApproveRouteLabel(array $outgoing): string
    {
        foreach ($outgoing as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $label = strtolower(trim((string) ($edge['label'] ?? '')));
            if (in_array($label, ['approve', 'approved', 'pass', 'yes'], true)) {
                return $label;
            }
        }
        return trim((string) (($outgoing[0]['label'] ?? 'pass')));
    }

    /** @param array<string,mixed> $run */
    private function setStageStatus(array &$run, string $nodeId, string $status): void
    {
        $stages = $run['stages'] ?? [];
        if (!is_array($stages)) {
            $stages = [];
        }

        $found = false;
        foreach ($stages as $index => $stage) {
            if (!is_array($stage)) {
                continue;
            }
            if ((string) ($stage['nodeId'] ?? '') !== $nodeId) {
                continue;
            }
            $stage['status'] = $status;
            $stage['name'] = $stage['name'] ?? $nodeId;
            $stage['index'] = $stage['index'] ?? $index;
            $stages[$index] = $stage;
            $found = true;
            break;
        }

        if (!$found) {
            $stages[] = [
                'index' => count($stages),
                'nodeId' => $nodeId,
                'name' => $nodeId,
                'status' => $status,
            ];
        }

        $run['stages'] = array_values($stages);
    }

    /**
      * @param array<string,mixed> $run
      * @return list<string>
      */
    private function completedNodes(array $run): array
    {
        $completed = ['start'];
        $stages = $run['stages'] ?? [];
        if (!is_array($stages)) {
            return $completed;
        }

        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            if ((string) ($stage['status'] ?? '') !== 'completed') {
                continue;
            }
            $nodeId = (string) ($stage['nodeId'] ?? '');
            if ($nodeId === '') {
                continue;
            }
            $completed[] = $nodeId;
        }

        return array_values(array_unique($completed));
    }

    private function writeArtifacts(string $runId, string $mode): void
    {
        $dir = $this->store->runDir($runId) . '/artifacts';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $run = $this->store->getRun($runId);
        $prompt = trim((string) ($run['originalPrompt'] ?? ''));
        if ($prompt === '') {
            $prompt = 'No original prompt recorded for this run.';
        }

        $runtime = is_array($run['_runtime'] ?? null) ? $run['_runtime'] : [];

        $responseSummary = [
            'runId' => $runId,
            'status' => (string) ($run['status'] ?? ''),
            'currentNodeId' => (string) ($run['currentNodeId'] ?? ''),
            'completedNodes' => $this->completedNodes($run),
            'finishedAtMs' => $run['finishedAtMs'] ?? null,
            'runtimeStep' => (int) ($runtime['step'] ?? 0),
            'lastNodeId' => (string) ($runtime['lastNodeId'] ?? ''),
            'lastValidation' => (string) ($runtime['lastValidation'] ?? ''),
        ];

        file_put_contents($dir . '/summary.txt', "run={$runId}\nmode={$mode}\n");
        file_put_contents($dir . '/events.log', implode("\n", array_map(static fn(array $e): string => json_encode($e) ?: '{}', $this->store->readEvents($runId))) . "\n");
        file_put_contents($dir . '/prompt.txt', $prompt . "\n");
        file_put_contents($dir . '/response.txt', (json_encode($responseSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . "\n");
        file_put_contents($dir . '/runtime.json', (json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . "\n");
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['completed', 'failed', 'cancelled'], true);
    }
}
