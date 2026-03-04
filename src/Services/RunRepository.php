<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Time;

final class RunRepository
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly DotService $dotService,
    ) {
    }

    public function ensureStorage(): void
    {
        if (!is_dir($this->runsRoot())) {
            mkdir($this->runsRoot(), 0777, true);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRuns(string $archiveMode = 'exclude'): array
    {
        $this->ensureStorage();
        $runs = [];
        $entries = scandir($this->runsRoot());
        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $manifestPath = $this->runPath($entry) . '/manifest.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $manifest = $this->loadJson($manifestPath);
            if ($manifest === null) {
                continue;
            }

            $archived = (bool) ($manifest['archived'] ?? false);
            if ($archiveMode === 'exclude' && $archived) {
                continue;
            }
            if ($archiveMode === 'only' && !$archived) {
                continue;
            }

            $runs[] = $manifest;
        }

        usort($runs, static fn (array $a, array $b): int => (int) ($b['startedAtMs'] ?? 0) <=> (int) ($a['startedAtMs'] ?? 0));
        return $runs;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createRun(array $payload, ?string $sourceRunId = null): array
    {
        $this->ensureStorage();

        $dotSource = (string) ($payload['dotSource'] ?? '');
        $displayName = trim((string) ($payload['displayName'] ?? ''));
        $fileName = trim((string) ($payload['fileName'] ?? 'pipeline.dot'));
        $simulate = (bool) ($payload['simulate'] ?? false);
        $autoApprove = array_key_exists('autoApprove', $payload) ? (bool) $payload['autoApprove'] : true;
        $originalPrompt = trim((string) ($payload['originalPrompt'] ?? ''));

        $id = $this->newRunId();
        $runPath = $this->runPath($id);
        mkdir($runPath, 0777, true);
        mkdir($runPath . '/artifacts', 0777, true);

        $familyId = $id;
        if ($sourceRunId !== null) {
            $source = $this->getRun($sourceRunId);
            if ($source !== null) {
                $familyId = (string) ($source['familyId'] ?? $sourceRunId);
            }
        }

        $nowMs = Time::nowMs();
        $stages = [];
        foreach ($this->dotService->extractStages($dotSource) as $index => $nodeId) {
            $stages[] = [
                'index' => $index,
                'nodeId' => $nodeId,
                'name' => ucfirst($nodeId),
                'status' => 'pending',
                'startedAtMs' => null,
                'durationMs' => null,
                'error' => '',
                'hasLog' => true,
            ];
            $stageDir = $runPath . '/' . $nodeId;
            mkdir($stageDir, 0777, true);
            file_put_contents($stageDir . '/prompt.md', "# Prompt\n\nGenerated prompt for stage {$nodeId}.\n");
            file_put_contents($stageDir . '/response.md', "# Response\n\nGenerated response for stage {$nodeId}.\n");
            file_put_contents($stageDir . '/status.json', (string) json_encode(['status' => 'pending'], JSON_PRETTY_PRINT));
        }

        $status = 'running';
        $currentNodeId = $stages[0]['nodeId'] ?? 'start';
        $finishedAtMs = null;
        $pendingQuestion = null;

        if ($autoApprove) {
            foreach ($stages as $i => $stage) {
                $stages[$i]['status'] = 'completed';
                $stages[$i]['startedAtMs'] = $nowMs;
                $stages[$i]['durationMs'] = 100;
                file_put_contents($runPath . '/' . $stage['nodeId'] . '/status.json', (string) json_encode(['status' => 'completed'], JSON_PRETTY_PRINT));
            }
            $status = 'completed';
            $finishedAtMs = $nowMs;
            $currentNodeId = $stages[count($stages) - 1]['nodeId'] ?? $currentNodeId;
        } else {
            if ($stages !== []) {
                $stages[0]['status'] = 'completed';
                $stages[0]['startedAtMs'] = $nowMs;
                $stages[0]['durationMs'] = 100;
                file_put_contents($runPath . '/' . $stages[0]['nodeId'] . '/status.json', (string) json_encode(['status' => 'completed'], JSON_PRETTY_PRINT));
            }

            if (count($stages) > 1) {
                $stages[1]['status'] = 'waiting_human';
                $stages[1]['startedAtMs'] = $nowMs;
                $currentNodeId = $stages[1]['nodeId'];
                file_put_contents($runPath . '/' . $stages[1]['nodeId'] . '/status.json', (string) json_encode(['status' => 'waiting_human'], JSON_PRETTY_PRINT));
                $pendingQuestion = [
                    'id' => 'q-1',
                    'stage' => $stages[1]['nodeId'],
                    'type' => 'MULTIPLE_CHOICE',
                    'text' => 'Approve continuation for this run?',
                    'options' => [
                        ['key' => 'A', 'label' => 'Approve'],
                        ['key' => 'F', 'label' => 'Request Fix'],
                    ],
                ];
                file_put_contents($runPath . '/question.json', (string) json_encode($pendingQuestion, JSON_PRETTY_PRINT));
            }
        }

        $manifest = [
            'id' => $id,
            'displayName' => $displayName !== '' ? $displayName : 'Run ' . substr($id, -6),
            'fileName' => $fileName,
            'status' => $status,
            'archived' => false,
            'simulate' => $simulate,
            'autoApprove' => $autoApprove,
            'familyId' => $familyId,
            'originalPrompt' => $originalPrompt,
            'startedAtMs' => $nowMs,
            'finishedAtMs' => $finishedAtMs,
            'currentNodeId' => $currentNodeId,
            'stages' => $stages,
            'logs' => [],
            'dotSource' => $dotSource,
        ];

        $checkpoint = [
            'current_node' => $manifest['currentNodeId'],
            'completed_nodes' => array_values(array_map(
                static fn (array $stage): string => (string) $stage['nodeId'],
                array_values(array_filter($stages, static fn (array $stage): bool => (string) $stage['status'] === 'completed')),
            )),
            'timestamp' => gmdate('c'),
        ];

        $context = [
            'graph.goal' => $originalPrompt,
            'run.id' => $id,
            'run.simulate' => $simulate,
        ];

        file_put_contents($runPath . '/manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($runPath . '/checkpoint.json', (string) json_encode($checkpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($runPath . '/context.json', (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($runPath . '/pipeline.dot', $dotSource);
        file_put_contents($runPath . '/artifacts/summary.txt', "Run {$id} status {$status}\n");

        $this->appendEvent($id, 'PipelineStarted', ['status' => 'running']);
        foreach ($stages as $stage) {
            $this->appendEvent($id, 'StageStarted', ['nodeId' => $stage['nodeId']]);
            if ($stage['status'] === 'completed') {
                $this->appendEvent($id, 'StageCompleted', ['nodeId' => $stage['nodeId'], 'durationMs' => 100]);
            }
            if ($stage['status'] === 'waiting_human') {
                $this->appendEvent($id, 'InterviewStarted', ['nodeId' => $stage['nodeId'], 'questionId' => 'q-1']);
            }
        }

        if ($status === 'completed') {
            $this->appendEvent($id, 'PipelineCompleted', ['status' => 'completed']);
        }

        $this->appendEvent($id, 'CheckpointSaved', ['nodeId' => $manifest['currentNodeId']]);

        return $manifest;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getRun(string $id): ?array
    {
        $manifestPath = $this->runPath($id) . '/manifest.json';
        $manifest = $this->loadJson($manifestPath);
        return is_array($manifest) ? $manifest : null;
    }

    /**
     * @param array<string,mixed> $run
     */
    public function saveRun(array $run): void
    {
        $id = (string) ($run['id'] ?? '');
        if ($id === '') {
            return;
        }
        file_put_contents($this->runPath($id) . '/manifest.json', (string) json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function checkpoint(string $id): ?array
    {
        return $this->loadJson($this->runPath($id) . '/checkpoint.json');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function context(string $id): ?array
    {
        return $this->loadJson($this->runPath($id) . '/context.json');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function questions(string $id): array
    {
        $q = $this->loadJson($this->runPath($id) . '/question.json');
        if (!is_array($q) || $q === []) {
            return [];
        }

        return [$q];
    }

    /**
     * @return array{ok:bool,error?:string,code?:string}
     */
    public function submitAnswer(string $id, string $qid, string $answer): array
    {
        $run = $this->getRun($id);
        if ($run === null) {
            return ['ok' => false, 'error' => 'run not found', 'code' => 'NOT_FOUND'];
        }

        $questionPath = $this->runPath($id) . '/question.json';
        $question = $this->loadJson($questionPath);
        if (!is_array($question) || $question === []) {
            return ['ok' => false, 'error' => 'question not found', 'code' => 'NOT_FOUND'];
        }

        if ((string) ($question['id'] ?? '') !== $qid) {
            return ['ok' => false, 'error' => 'question not found', 'code' => 'NOT_FOUND'];
        }

        $valid = false;
        foreach (($question['options'] ?? []) as $opt) {
            if (is_array($opt) && (string) ($opt['key'] ?? '') === $answer) {
                $valid = true;
            }
        }

        if (!$valid) {
            return ['ok' => false, 'error' => 'invalid answer', 'code' => 'BAD_REQUEST'];
        }

        unlink($questionPath);
        $run['status'] = $answer === 'A' ? 'completed' : 'failed';
        $run['finishedAtMs'] = Time::nowMs();

        $stages = is_array($run['stages'] ?? null) ? $run['stages'] : [];
        foreach ($stages as $i => $stage) {
            if (!is_array($stage)) {
                continue;
            }
            if ((string) ($stage['status'] ?? '') === 'waiting_human') {
                $stages[$i]['status'] = $answer === 'A' ? 'completed' : 'failed';
                $stages[$i]['durationMs'] = 200;
                file_put_contents($this->runPath($id) . '/' . $stage['nodeId'] . '/status.json', (string) json_encode(['status' => $stages[$i]['status']], JSON_PRETTY_PRINT));
            } elseif ((string) ($stage['status'] ?? '') === 'pending') {
                $stages[$i]['status'] = $answer === 'A' ? 'completed' : 'skipped';
            }
        }

        $run['stages'] = $stages;
        $run['currentNodeId'] = (string) ($stages[count($stages) - 1]['nodeId'] ?? $run['currentNodeId']);
        $this->saveRun($run);

        $checkpoint = [
            'current_node' => $run['currentNodeId'],
            'completed_nodes' => array_values(array_map(
                static fn (array $stage): string => (string) $stage['nodeId'],
                array_values(array_filter($stages, static fn (array $stage): bool => (string) $stage['status'] === 'completed')),
            )),
            'timestamp' => gmdate('c'),
        ];
        file_put_contents($this->runPath($id) . '/checkpoint.json', (string) json_encode($checkpoint, JSON_PRETTY_PRINT));

        $this->appendEvent($id, 'InterviewCompleted', ['questionId' => $qid, 'answer' => $answer]);
        if ($run['status'] === 'completed') {
            $this->appendEvent($id, 'PipelineCompleted', ['status' => 'completed']);
        } else {
            $this->appendEvent($id, 'PipelineFailed', ['status' => 'failed']);
        }
        $this->appendEvent($id, 'CheckpointSaved', ['nodeId' => $run['currentNodeId']]);

        return ['ok' => true];
    }

    public function cancelRun(string $id): array
    {
        $run = $this->getRun($id);
        if ($run === null) {
            return ['ok' => false, 'error' => 'run not found', 'code' => 'NOT_FOUND'];
        }

        if ((string) $run['status'] !== 'running') {
            return ['ok' => false, 'error' => 'run is not running', 'code' => 'INVALID_STATE'];
        }

        $run['status'] = 'cancelled';
        $run['finishedAtMs'] = Time::nowMs();
        $this->saveRun($run);
        $this->appendEvent($id, 'PipelineFailed', ['status' => 'cancelled']);

        return ['ok' => true];
    }

    public function deleteRun(string $id): array
    {
        $run = $this->getRun($id);
        if ($run === null) {
            return ['ok' => false, 'error' => 'run not found', 'code' => 'NOT_FOUND'];
        }

        if ((string) $run['status'] === 'running') {
            return ['ok' => false, 'error' => 'cannot delete running run', 'code' => 'INVALID_STATE'];
        }

        $this->deleteDir($this->runPath($id));
        return ['ok' => true];
    }

    public function setArchived(string $id, bool $archived): array
    {
        $run = $this->getRun($id);
        if ($run === null) {
            return ['ok' => false, 'error' => 'run not found', 'code' => 'NOT_FOUND'];
        }

        if ((string) $run['status'] === 'running') {
            return ['ok' => false, 'error' => 'run is running', 'code' => 'INVALID_STATE'];
        }

        $run['archived'] = $archived;
        $this->saveRun($run);
        return ['ok' => true];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listArtifacts(string $id): array
    {
        $runPath = $this->runPath($id);
        if (!is_dir($runPath)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($runPath, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $fullPath = $fileInfo->getPathname();
            $rel = substr($fullPath, strlen($runPath) + 1);
            if (!is_string($rel)) {
                continue;
            }
            if ($rel === 'manifest.json' || $rel === 'checkpoint.json' || $rel === 'events.ndjson' || $rel === 'context.json' || $rel === 'question.json' || $rel === 'pipeline.dot') {
                continue;
            }
            $isText = $this->isTextFile($fullPath);
            $files[] = [
                'path' => str_replace('\\', '/', $rel),
                'sizeBytes' => filesize($fullPath),
                'isText' => $isText,
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));
        return $files;
    }

    public function artifactPath(string $id, string $relPath): ?string
    {
        if (str_contains($relPath, '..') || str_starts_with($relPath, '/')) {
            return null;
        }

        $runPath = realpath($this->runPath($id));
        if ($runPath === false) {
            return null;
        }

        $full = realpath($runPath . '/' . $relPath);
        if ($full === false || !str_starts_with($full, $runPath . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($full) ? $full : null;
    }

    public function buildArtifactsZip(string $id): ?string
    {
        $runPath = $this->runPath($id);
        if (!is_dir($runPath)) {
            return null;
        }

        if (!class_exists('ZipArchive')) {
            return null;
        }

        $zipPath = $this->projectRoot . '/.scratch/verification/SPRINT-002/final/' . $id . '-artifacts.zip';
        $zip = new \ZipArchive();
        $ok = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($ok !== true) {
            return null;
        }

        foreach ($this->listArtifacts($id) as $artifact) {
            $rel = (string) ($artifact['path'] ?? '');
            $full = $this->artifactPath($id, $rel);
            if ($full !== null) {
                $zip->addFile($full, $rel);
            }
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function streamFrames(?string $runId): array
    {
        $frames = [];
        if ($runId === null) {
            $frames[] = ['type' => 'Snapshot', 'payload' => ['runs' => $this->listRuns('all')]];
            foreach ($this->listRuns('all') as $run) {
                $id = (string) ($run['id'] ?? '');
                foreach ($this->loadEvents($id) as $event) {
                    $frames[] = $event;
                }
            }
        } else {
            $run = $this->getRun($runId);
            $frames[] = ['type' => 'Snapshot', 'payload' => ['run' => $run]];
            foreach ($this->loadEvents($runId) as $event) {
                $frames[] = $event;
            }
        }

        return $frames;
    }

    public function appendEvent(string $runId, string $type, array $payload): void
    {
        $event = [
            'runId' => $runId,
            'tsMs' => Time::nowMs(),
            'type' => $type,
            'payload' => $payload,
        ];

        $path = $this->runPath($runId) . '/events.ndjson';
        file_put_contents($path, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }

    private function runsRoot(): string
    {
        return $this->projectRoot . '/.scratch/runtime/runs';
    }

    private function runPath(string $id): string
    {
        return $this->runsRoot() . '/' . $id;
    }

    private function newRunId(): string
    {
        return 'run-' . (string) Time::nowMs() . '-' . (string) random_int(1000, 9999);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadEvents(string $runId): array
    {
        $path = $this->runPath($runId) . '/events.ndjson';
        if (!is_file($path)) {
            return [];
        }

        $events = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    private function isTextFile(string $path): bool
    {
        $sample = file_get_contents($path, false, null, 0, 2048);
        if (!is_string($sample)) {
            return false;
        }

        return !preg_match('/[\x00-\x08\x0E-\x1F]/', $sample);
    }
}
