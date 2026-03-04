<?php

declare(strict_types=1);

namespace AttractorPhp\Domain;

use AttractorPhp\Http\ApiError;
use AttractorPhp\Storage\RunStore;

final class PipelineService
{
    public function __construct(
        private readonly RunStore $store,
        private readonly DotService $dotService
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

    /** @param array<string,mixed> $input
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
        $run = $this->store->createRun($input);
        $id = (string) $run['id'];

        $run['status'] = 'running';
        $run['currentNodeId'] = 'plan';
        $run['stages'] = [
            ['index' => 0, 'nodeId' => 'plan', 'name' => 'plan', 'status' => 'running'],
        ];
        $this->store->saveRun($id, $run);
        $this->store->emitEvent($id, 'PipelineStarted', ['nodeId' => 'start']);
        $this->store->emitEvent($id, 'StageStarted', ['nodeId' => 'plan']);
        $this->store->emitEvent($id, 'CheckpointSaved', ['currentNodeId' => 'plan']);
        $this->store->saveCheckpoint($id, [
            'current_node' => 'plan',
            'completed_nodes' => ['start'],
            'timestamp' => gmdate('c'),
        ]);

        $hasHumanGate = (bool) preg_match('/human|approve|review_gate/i', (string) $run['dotSource']);
        $shouldStayRunning = str_contains((string) $run['dotSource'], 'STATUS_RUNNING');

        if ($hasHumanGate && (bool) $run['autoApprove'] === false) {
            $run = $this->store->getRun($id);
            $this->setStageStatus($run, 'plan', 'completed');
            $this->setStageStatus($run, 'review_gate', 'waiting_human');
            $run['status'] = 'waiting_human';
            $run['currentNodeId'] = 'review_gate';
            unset($run['_runtime']);
            $this->store->saveRun($id, $run);
            $this->store->saveQuestions($id, [[
                'id' => 'q-1',
                'stage' => 'review_gate',
                'type' => 'MULTIPLE_CHOICE',
                'text' => 'Approve changes?',
                'options' => [
                    ['key' => 'A', 'label' => 'Approve'],
                    ['key' => 'F', 'label' => 'Fix'],
                ],
            ]]);
            $this->store->emitEvent($id, 'StageCompleted', ['nodeId' => 'plan']);
            $this->store->emitEvent($id, 'InterviewStarted', ['questionId' => 'q-1']);
            $this->store->emitEvent($id, 'CheckpointSaved', ['currentNodeId' => 'review_gate']);
            $this->writeArtifacts($id, 'waiting_human');
            $this->store->saveCheckpoint($id, [
                'current_node' => 'review_gate',
                'completed_nodes' => ['start', 'plan'],
                'timestamp' => gmdate('c'),
            ]);
            return $this->store->getRun($id);
        }

        if ($shouldStayRunning) {
            $run = $this->store->getRun($id);
            $this->setStageStatus($run, 'plan', 'completed');
            $this->setStageStatus($run, 'implement', 'running');
            $run['status'] = 'running';
            $run['currentNodeId'] = 'implement';
            $run['_runtime'] = ['mode' => 'manual'];
            $this->store->saveRun($id, $run);
            $this->store->emitEvent($id, 'StageCompleted', ['nodeId' => 'plan']);
            $this->store->emitEvent($id, 'StageStarted', ['nodeId' => 'implement']);
            $this->store->emitEvent($id, 'CheckpointSaved', ['currentNodeId' => 'implement']);
            $this->writeArtifacts($id, 'running');
            $this->store->saveCheckpoint($id, [
                'current_node' => 'implement',
                'completed_nodes' => ['start', 'plan'],
                'timestamp' => gmdate('c'),
            ]);
            return $this->store->getRun($id);
        }

        $run = $this->store->getRun($id);
        if ((bool) $run['simulate']) {
            $this->completeRunNow($id, $run);
            return $this->store->getRun($id);
        }

        $intervalMs = 250;
        $startedAtMs = (int) ($run['startedAtMs'] ?? (int) floor(microtime(true) * 1000));
        $run['_runtime'] = [
            'mode' => 'auto',
            'nextEventIndex' => 0,
            'timeline' => [
                ['atMs' => $startedAtMs + ($intervalMs * 1), 'kind' => 'advance', 'from' => 'plan', 'to' => 'implement'],
                ['atMs' => $startedAtMs + ($intervalMs * 2), 'kind' => 'advance', 'from' => 'implement', 'to' => 'test'],
                ['atMs' => $startedAtMs + ($intervalMs * 3), 'kind' => 'advance', 'from' => 'test', 'to' => 'exit'],
                ['atMs' => $startedAtMs + ($intervalMs * 4), 'kind' => 'finish', 'node' => 'exit'],
            ],
        ];
        $this->store->saveRun($id, $run);

        return $this->store->getRun($id);
    }

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
        $this->tickRun($runId);
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

        $valid = false;
        foreach (($question['options'] ?? []) as $option) {
            if ((string) ($option['key'] ?? '') === $answerKey) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            throw new ApiError(400, 'BAD_REQUEST', 'invalid answer option');
        }

        $this->store->saveQuestions($runId, []);
        $run['status'] = 'completed';
        $run['currentNodeId'] = 'exit';
        $run['finishedAtMs'] = (int) floor(microtime(true) * 1000);
        unset($run['_runtime']);
        $this->setStageStatus($run, 'review_gate', 'completed');
        $this->setStageStatus($run, 'exit', 'completed');
        $this->store->saveRun($runId, $run);

        $this->store->emitEvent($runId, 'InterviewCompleted', ['questionId' => $questionId, 'answer' => $answerKey]);
        $this->store->emitEvent($runId, 'PipelineCompleted', ['status' => 'completed']);
        $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => 'exit']);
        $this->writeArtifacts($runId, 'completed-after-human');
        $this->store->saveCheckpoint($runId, [
            'current_node' => 'exit',
            'completed_nodes' => ['start', 'plan', 'review_gate', 'exit'],
            'timestamp' => gmdate('c'),
        ]);

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
            'simulate' => (bool) ($source['simulate'] ?? false),
            'autoApprove' => (bool) ($source['autoApprove'] ?? true),
            'familyId' => $familyId,
            'originalPrompt' => $originalPrompt,
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
        if (!is_array($runtime) || (string) ($runtime['mode'] ?? '') !== 'auto') {
            return;
        }

        $timeline = $runtime['timeline'] ?? [];
        if (!is_array($timeline)) {
            return;
        }

        $nextIndex = (int) ($runtime['nextEventIndex'] ?? 0);
        $now = (int) floor(microtime(true) * 1000);

        while ($nextIndex < count($timeline)) {
            $event = $timeline[$nextIndex];
            if (!is_array($event)) {
                $nextIndex++;
                continue;
            }
            if ($now < (int) ($event['atMs'] ?? PHP_INT_MAX)) {
                break;
            }

            $run = $this->store->getRun($runId);
            if ((string) ($run['status'] ?? '') !== 'running') {
                break;
            }
            $this->applyAutoEvent($runId, $run, $event);
            $nextIndex++;

            $run = $this->store->getRun($runId);
            if ((string) ($run['status'] ?? '') !== 'running') {
                break;
            }
            $run['_runtime']['nextEventIndex'] = $nextIndex;
            $this->store->saveRun($runId, $run);
        }
    }

    /** @param array<string,mixed> $run */
    private function completeRunNow(string $runId, array $run): void
    {
        $this->setStageStatus($run, 'plan', 'completed');
        $this->setStageStatus($run, 'implement', 'completed');
        $this->setStageStatus($run, 'test', 'completed');
        $this->setStageStatus($run, 'exit', 'completed');
        $run['status'] = 'completed';
        $run['currentNodeId'] = 'exit';
        $run['finishedAtMs'] = (int) floor(microtime(true) * 1000);
        unset($run['_runtime']);
        $this->store->saveRun($runId, $run);
        $this->store->emitEvent($runId, 'StageCompleted', ['nodeId' => 'plan']);
        $this->store->emitEvent($runId, 'StageCompleted', ['nodeId' => 'implement']);
        $this->store->emitEvent($runId, 'StageCompleted', ['nodeId' => 'test']);
        $this->store->emitEvent($runId, 'StageCompleted', ['nodeId' => 'exit']);
        $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => 'exit']);
        $this->store->emitEvent($runId, 'PipelineCompleted', ['status' => 'completed']);
        $this->writeArtifacts($runId, 'completed');
        $this->store->saveCheckpoint($runId, [
            'current_node' => 'exit',
            'completed_nodes' => ['start', 'plan', 'implement', 'test', 'exit'],
            'timestamp' => gmdate('c'),
        ]);
    }

    /** @param array<string,mixed> $run
      * @param array<string,mixed> $event
      */
    private function applyAutoEvent(string $runId, array $run, array $event): void
    {
        $kind = (string) ($event['kind'] ?? '');
        if ($kind === 'advance') {
            $from = (string) ($event['from'] ?? '');
            $to = (string) ($event['to'] ?? '');
            if ($from === '' || $to === '') {
                return;
            }

            $this->setStageStatus($run, $from, 'completed');
            $this->setStageStatus($run, $to, 'running');
            $run['currentNodeId'] = $to;
            $this->store->saveRun($runId, $run);
            $this->store->emitEvent($runId, 'StageCompleted', ['nodeId' => $from]);
            $this->store->emitEvent($runId, 'StageStarted', ['nodeId' => $to]);
            $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => $to]);
            $this->store->saveCheckpoint($runId, [
                'current_node' => $to,
                'completed_nodes' => $this->completedNodes($run),
                'timestamp' => gmdate('c'),
            ]);
            return;
        }

        if ($kind === 'finish') {
            $node = (string) ($event['node'] ?? 'exit');
            $this->setStageStatus($run, $node, 'completed');
            $run['status'] = 'completed';
            $run['currentNodeId'] = $node;
            $run['finishedAtMs'] = (int) floor(microtime(true) * 1000);
            unset($run['_runtime']);
            $this->store->saveRun($runId, $run);
            $this->store->emitEvent($runId, 'StageCompleted', ['nodeId' => $node]);
            $this->store->emitEvent($runId, 'CheckpointSaved', ['currentNodeId' => $node]);
            $this->store->emitEvent($runId, 'PipelineCompleted', ['status' => 'completed']);
            $this->writeArtifacts($runId, 'completed');
            $this->store->saveCheckpoint($runId, [
                'current_node' => $node,
                'completed_nodes' => $this->completedNodes($run),
                'timestamp' => gmdate('c'),
            ]);
        }
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

    /** @param array<string,mixed> $run
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

        $responseSummary = [
            'runId' => $runId,
            'status' => (string) ($run['status'] ?? ''),
            'currentNodeId' => (string) ($run['currentNodeId'] ?? ''),
            'completedNodes' => $this->completedNodes($run),
            'finishedAtMs' => $run['finishedAtMs'] ?? null,
        ];

        file_put_contents($dir . '/summary.txt', "run={$runId}\nmode={$mode}\n");
        file_put_contents($dir . '/events.log', implode("\n", array_map(static fn(array $e): string => json_encode($e) ?: '{}', $this->store->readEvents($runId))) . "\n");
        file_put_contents($dir . '/prompt.txt', $prompt . "\n");
        file_put_contents($dir . '/response.txt', (json_encode($responseSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . "\n");
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
