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

        $this->store->emitEvent($id, 'PipelineStarted', ['nodeId' => 'start']);
        $this->store->emitEvent($id, 'StageStarted', ['nodeId' => 'plan']);

        $hasHumanGate = (bool) preg_match('/human|approve|review_gate/i', $run['dotSource']);
        $shouldStayRunning = str_contains((string) $run['dotSource'], 'STATUS_RUNNING');

        if ($hasHumanGate && (bool) $run['autoApprove'] === false) {
            $run['status'] = 'waiting_human';
            $run['currentNodeId'] = 'review_gate';
            $run['stages'] = [
                ['index' => 0, 'nodeId' => 'plan', 'name' => 'plan', 'status' => 'completed'],
                ['index' => 1, 'nodeId' => 'review_gate', 'name' => 'review_gate', 'status' => 'waiting_human'],
            ];
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
            $run['status'] = 'running';
            $run['currentNodeId'] = 'implement';
            $run['stages'] = [
                ['index' => 0, 'nodeId' => 'plan', 'name' => 'plan', 'status' => 'completed'],
                ['index' => 1, 'nodeId' => 'implement', 'name' => 'implement', 'status' => 'running'],
            ];
            $this->store->saveRun($id, $run);
            $this->store->emitEvent($id, 'StageCompleted', ['nodeId' => 'plan']);
            $this->store->emitEvent($id, 'CheckpointSaved', ['currentNodeId' => 'implement']);
            $this->writeArtifacts($id, 'running');
            $this->store->saveCheckpoint($id, [
                'current_node' => 'implement',
                'completed_nodes' => ['start', 'plan'],
                'timestamp' => gmdate('c'),
            ]);
            return $this->store->getRun($id);
        }

        $run['status'] = 'completed';
        $run['currentNodeId'] = 'exit';
        $run['finishedAtMs'] = (int) floor(microtime(true) * 1000);
        $run['stages'] = [
            ['index' => 0, 'nodeId' => 'plan', 'name' => 'plan', 'status' => 'completed'],
            ['index' => 1, 'nodeId' => 'implement', 'name' => 'implement', 'status' => 'completed'],
            ['index' => 2, 'nodeId' => 'test', 'name' => 'test', 'status' => 'completed'],
            ['index' => 3, 'nodeId' => 'exit', 'name' => 'exit', 'status' => 'completed'],
        ];

        $this->store->saveRun($id, $run);
        $this->store->emitEvent($id, 'StageCompleted', ['nodeId' => 'plan']);
        $this->store->emitEvent($id, 'StageCompleted', ['nodeId' => 'implement']);
        $this->store->emitEvent($id, 'StageCompleted', ['nodeId' => 'test']);
        $this->store->emitEvent($id, 'CheckpointSaved', ['currentNodeId' => 'exit']);
        $this->store->emitEvent($id, 'PipelineCompleted', ['status' => 'completed']);
        $this->writeArtifacts($id, 'completed');
        $this->store->saveCheckpoint($id, [
            'current_node' => 'exit',
            'completed_nodes' => ['start', 'plan', 'implement', 'test', 'exit'],
            'timestamp' => gmdate('c'),
        ]);

        return $this->store->getRun($id);
    }

    public function cancel(string $runId): array
    {
        $run = $this->store->getRun($runId);
        if ((string) ($run['status'] ?? '') !== 'running') {
            throw new ApiError(409, 'INVALID_STATE', 'only running runs can be cancelled');
        }

        $run['status'] = 'cancelled';
        $run['finishedAtMs'] = (int) floor(microtime(true) * 1000);
        $this->store->saveRun($runId, $run);
        $this->store->emitEvent($runId, 'PipelineFailed', ['reason' => 'cancelled']);
        return $run;
    }

    public function delete(string $runId): void
    {
        $run = $this->store->getRun($runId);
        if ((string) ($run['status'] ?? '') === 'running') {
            throw new ApiError(409, 'INVALID_STATE', 'cannot delete running run');
        }

        $this->deleteDir($this->store->runDir($runId));
    }

    public function setArchived(string $runId, bool $archived): array
    {
        $run = $this->store->getRun($runId);
        if ((string) ($run['status'] ?? '') === 'running') {
            throw new ApiError(409, 'INVALID_STATE', 'running run cannot be archived/unarchived');
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
        $run['stages'][] = ['index' => count($run['stages']), 'nodeId' => 'exit', 'name' => 'exit', 'status' => 'completed'];
        $this->store->saveRun($runId, $run);

        $this->store->emitEvent($runId, 'InterviewCompleted', ['questionId' => $questionId, 'answer' => $answerKey]);
        $this->store->emitEvent($runId, 'PipelineCompleted', ['status' => 'completed']);
        $this->writeArtifacts($runId, 'completed-after-human');
        $this->store->saveCheckpoint($runId, [
            'current_node' => 'exit',
            'completed_nodes' => ['start', 'plan', 'review_gate', 'exit'],
            'timestamp' => gmdate('c'),
        ]);

        return $run;
    }

    /** @return array<string,mixed> */
    public function iterateRun(string $runId, string $dotSource, string $originalPrompt): array
    {
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
        $run = $this->store->getRun($runId);
        return $this->dotService->render((string) ($run['dotSource'] ?? 'digraph empty {}'));
    }

    private function writeArtifacts(string $runId, string $mode): void
    {
        $dir = $this->store->runDir($runId) . '/artifacts';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir . '/summary.txt', "run={$runId}\nmode={$mode}\n");
        file_put_contents($dir . '/events.log', implode("\n", array_map(static fn(array $e): string => json_encode($e) ?: '{}', $this->store->readEvents($runId))) . "\n");
        file_put_contents($dir . '/prompt.txt', 'Synthetic prompt artifact');
        file_put_contents($dir . '/response.txt', 'Synthetic response artifact');
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
}
