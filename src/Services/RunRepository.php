<?php

declare(strict_types=1);

namespace App\Services;

use App\Agent\Exec\LocalExecutionEnvironment;
use App\Support\Time;

final class RunRepository
{
    private LocalExecutionEnvironment $executionEnv;

    /**
     * @var array<string,list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'queued' => ['running', 'cancelled'],
        'running' => ['completed', 'failed', 'cancelled'],
        'completed' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    /**
     * @var list<string>
     */
    private const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

    public function __construct(
        private readonly string $projectRoot,
        private readonly DotService $dotService,
    ) {
        $this->executionEnv = new LocalExecutionEnvironment($this->projectRoot);
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
        $originalPrompt = trim((string) ($payload['originalPrompt'] ?? ''));

        $provider = trim(strtolower((string) ($payload['provider'] ?? '')));
        if ($provider === '') {
            $provider = $this->defaultProvider();
        }
        $model = trim((string) ($payload['model'] ?? ''));

        $graph = $this->parseGraph($dotSource);

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
        foreach ($graph['stageOrder'] as $index => $nodeId) {
            $stages[] = [
                'index' => $index,
                'nodeId' => $nodeId,
                'name' => $this->stageName($nodeId, $graph),
                'status' => 'pending',
                'startedAtMs' => null,
                'durationMs' => null,
                'error' => '',
                'hasLog' => true,
            ];
            $stageDir = $runPath . '/' . $nodeId;
            if (!is_dir($stageDir)) {
                mkdir($stageDir, 0777, true);
            }
            file_put_contents($stageDir . '/status.json', (string) json_encode(['status' => 'pending'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $manifest = [
            'id' => $id,
            'displayName' => $displayName !== '' ? $displayName : 'Run ' . substr($id, -6),
            'fileName' => $fileName,
            'status' => 'queued',
            'archived' => false,
            'familyId' => $familyId,
            'provider' => $provider,
            'model' => $model,
            'originalPrompt' => $originalPrompt,
            'startedAtMs' => $nowMs,
            'finishedAtMs' => null,
            'currentNodeId' => $graph['startNodeId'],
            'stages' => $stages,
            'logs' => [],
            'dotSource' => $dotSource,
        ];

        $context = [
            'graph.goal' => $originalPrompt,
            'run.id' => $id,
            'run.provider' => $provider,
            'run.model' => $model,
        ];

        file_put_contents($runPath . '/manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($runPath . '/context.json', (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($runPath . '/pipeline.dot', $dotSource);
        $this->saveCheckpoint($id, $manifest);

        $this->appendEvent($id, 'PipelineQueued', ['status' => 'queued']);
        $this->spawnWorker($id);

        return $manifest;
    }

    /**
     * Real runtime worker loop for one run id.
     */
    public function processRun(string $id): void
    {
        $steps = 0;
        while ($steps < 256) {
            $run = $this->getRun($id);
            if ($run === null) {
                return;
            }

            $status = (string) ($run['status'] ?? '');
            if ($status === '') {
                $this->failRun($id, 'manifest status missing');
                return;
            }

            if (in_array($status, self::TERMINAL_STATUSES, true)) {
                return;
            }

            if ($status === 'queued') {
                if (!$this->canTransition('queued', 'running')) {
                    $this->failRun($id, 'invalid queued->running transition');
                    return;
                }
                $run['status'] = 'running';
                $this->saveRun($run);
                $this->appendEvent($id, 'PipelineStarted', ['status' => 'running']);
                $run = $this->getRun($id);
                if ($run === null) {
                    return;
                }
            }

            $questionPath = $this->runPath($id) . '/question.json';
            if (is_file($questionPath)) {
                return;
            }

            $dotSource = (string) ($run['dotSource'] ?? '');
            $graph = $this->parseGraph($dotSource);
            $currentNodeId = trim((string) ($run['currentNodeId'] ?? ''));
            if ($currentNodeId === '') {
                $currentNodeId = $graph['startNodeId'];
            }

            $node = $graph['nodes'][$currentNodeId] ?? null;
            if (!is_array($node)) {
                $this->failRun($id, 'node not found: ' . $currentNodeId);
                return;
            }

            $outgoing = $graph['outgoing'][$currentNodeId] ?? [];
            if ($this->isTerminalNode($currentNodeId, $node, $outgoing)) {
                $this->markStageCompletedControl($run, $currentNodeId, 'Reached terminal node.');
                $run['status'] = 'completed';
                $run['finishedAtMs'] = Time::nowMs();
                $run['currentNodeId'] = $currentNodeId;
                $this->saveRun($run);
                $this->saveCheckpoint($id, $run);
                $this->appendEvent($id, 'PipelineCompleted', ['status' => 'completed']);
                $this->appendEvent($id, 'CheckpointSaved', ['nodeId' => $currentNodeId]);
                return;
            }

            if ($this->isHumanGateNode($currentNodeId, $node)) {
                $this->startHumanGate($run, $currentNodeId, $outgoing);
                return;
            }

            $prompt = $this->buildStagePrompt($run, $currentNodeId, $node, $outgoing);
            $startedAtMs = Time::nowMs();
            $this->markStageStarted($run, $currentNodeId, $startedAtMs);
            $this->writeStagePrompt($id, $currentNodeId, $prompt);
            $this->saveRun($run);
            $this->appendEvent($id, 'StageStarted', ['nodeId' => $currentNodeId]);

            try {
                if ($this->isControlNode($currentNodeId, $node)) {
                    $completionText = 'Control node completed.';
                    $providerUsed = 'engine';
                    $modelUsed = 'engine';
                } else {
                    $stageProvider = trim(strtolower((string) ($node['attrs']['llm_provider'] ?? (string) ($run['provider'] ?? ''))));
                    $stageModel = trim((string) ($node['attrs']['llm_model'] ?? (string) ($run['model'] ?? '')));
                    $completion = $this->dotService->completeText(
                        systemPrompt: $this->stageSystemPrompt(),
                        userPrompt: $prompt,
                        options: [
                            'provider' => $stageProvider,
                            'model' => $stageModel,
                        ],
                    );
                    $completionText = (string) ($completion['text'] ?? '');
                    $providerUsed = (string) ($completion['provider'] ?? '');
                    $modelUsed = (string) ($completion['model'] ?? '');
                }
            } catch (\Throwable $e) {
                $durationMs = max(1, Time::nowMs() - $startedAtMs);
                $this->markStageFailed($run, $currentNodeId, $durationMs, $e->getMessage());
                $this->saveRun($run);
                $this->writeStageResponse($id, $currentNodeId, "Runtime error: {$e->getMessage()}\n");
                $this->appendEvent($id, 'StageFailed', ['nodeId' => $currentNodeId, 'error' => $e->getMessage()]);
                $this->failRun($id, $e->getMessage());
                return;
            }

            $durationMs = max(1, Time::nowMs() - $startedAtMs);
            $this->markStageCompleted($run, $currentNodeId, $durationMs);

            $actionResult = [
                'artifactPaths' => [],
                'commandLogs' => [],
                'summary' => '',
            ];
            if (!$this->isControlNode($currentNodeId, $node)) {
                $actionResult = $this->executeStageActions($id, $currentNodeId, $completionText);
            }

            $this->writeStageResponse(
                $id,
                $currentNodeId,
                $this->formatStageResponse(
                    $completionText,
                    $providerUsed,
                    $modelUsed,
                    $actionResult['artifactPaths'],
                    $actionResult['commandLogs'],
                    $actionResult['summary'],
                ),
            );
            $this->appendEvent($id, 'StageCompleted', ['nodeId' => $currentNodeId, 'durationMs' => $durationMs]);

            $context = $this->context($id) ?? [];
            $context['stage.' . $currentNodeId . '.response'] = $this->truncate($completionText, 4000);
            if ($actionResult['artifactPaths'] !== []) {
                $context['stage.' . $currentNodeId . '.artifacts'] = $actionResult['artifactPaths'];
            }

            $validationOutcome = null;
            if ($this->isValidationNode($currentNodeId, $node)) {
                $validation = $this->parseValidationOutcome($completionText);
                $validationOutcome = $validation['outcome'];
                $context['stage.' . $currentNodeId . '.validation_outcome'] = $validation['outcome'];
                $context['stage.' . $currentNodeId . '.validation_reason'] = $validation['reason'];
            }
            file_put_contents($this->runPath($id) . '/context.json', (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $nextNodeId = $this->selectNextNode($outgoing, $validationOutcome);
            if ($nextNodeId === null) {
                $run['status'] = 'completed';
                $run['finishedAtMs'] = Time::nowMs();
                $run['currentNodeId'] = $currentNodeId;
                $this->saveRun($run);
                $this->saveCheckpoint($id, $run);
                $this->appendEvent($id, 'PipelineCompleted', ['status' => 'completed']);
                $this->appendEvent($id, 'CheckpointSaved', ['nodeId' => $currentNodeId]);
                return;
            }

            $run['currentNodeId'] = $nextNodeId;
            $this->saveRun($run);
            $this->saveCheckpoint($id, $run);
            $this->appendEvent($id, 'CheckpointSaved', ['nodeId' => $nextNodeId]);

            $steps++;
        }

        $this->failRun($id, 'maximum stage execution steps exceeded');
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
        if ((string) ($run['status'] ?? '') !== 'running') {
            return ['ok' => false, 'error' => 'run is not awaiting answers', 'code' => 'INVALID_STATE'];
        }

        $questionPath = $this->runPath($id) . '/question.json';
        $question = $this->loadJson($questionPath);
        if (!is_array($question) || $question === []) {
            return ['ok' => false, 'error' => 'question not found', 'code' => 'NOT_FOUND'];
        }

        if ((string) ($question['id'] ?? '') !== $qid) {
            return ['ok' => false, 'error' => 'question not found', 'code' => 'NOT_FOUND'];
        }

        $selected = null;
        foreach (($question['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            if ((string) ($option['key'] ?? '') === $answer) {
                $selected = $option;
                break;
            }
        }
        if (!is_array($selected)) {
            return ['ok' => false, 'error' => 'invalid answer', 'code' => 'BAD_REQUEST'];
        }

        if (is_file($questionPath)) {
            unlink($questionPath);
        }

        $stageNodeId = (string) ($question['stage'] ?? '');
        if ($stageNodeId !== '') {
            $this->setStageStatus($run, $stageNodeId, 'completed', null, 1, 1, '');
            $this->writeStageStatus($id, $stageNodeId, 'completed', 1, '');
        }

        $nextNodeId = trim((string) ($selected['target'] ?? ''));
        if ($nextNodeId === '') {
            $nextNodeId = (string) ($run['currentNodeId'] ?? '');
        }
        $run['currentNodeId'] = $nextNodeId;
        $this->saveRun($run);
        $this->saveCheckpoint($id, $run);
        $this->appendEvent($id, 'InterviewCompleted', ['questionId' => $qid, 'answer' => $answer, 'nextNodeId' => $nextNodeId]);
        $this->appendEvent($id, 'CheckpointSaved', ['nodeId' => $nextNodeId]);

        $this->spawnWorker($id);
        return ['ok' => true];
    }

    public function cancelRun(string $id): array
    {
        $run = $this->getRun($id);
        if ($run === null) {
            return ['ok' => false, 'error' => 'run not found', 'code' => 'NOT_FOUND'];
        }

        $status = (string) ($run['status'] ?? '');
        if (!$this->canTransition($status, 'cancelled')) {
            return ['ok' => false, 'error' => 'run is not running', 'code' => 'INVALID_STATE'];
        }

        $run['status'] = 'cancelled';
        $run['finishedAtMs'] = Time::nowMs();
        $this->saveRun($run);
        $questionPath = $this->runPath($id) . '/question.json';
        if (is_file($questionPath)) {
            unlink($questionPath);
        }
        $this->appendEvent($id, 'PipelineFailed', ['status' => 'cancelled']);

        return ['ok' => true];
    }

    public function deleteRun(string $id): array
    {
        $run = $this->getRun($id);
        if ($run === null) {
            return ['ok' => false, 'error' => 'run not found', 'code' => 'NOT_FOUND'];
        }

        if (!$this->isTerminalStatus((string) ($run['status'] ?? ''))) {
            return ['ok' => false, 'error' => 'cannot delete non-terminal run', 'code' => 'INVALID_STATE'];
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

        if (!$this->isTerminalStatus((string) ($run['status'] ?? ''))) {
            return ['ok' => false, 'error' => 'run is not terminal', 'code' => 'INVALID_STATE'];
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

    private function defaultProvider(): string
    {
        if (trim((string) getenv('OPENAI_API_KEY')) !== '') {
            return 'openai';
        }
        if (trim((string) getenv('ANTHROPIC_API_KEY')) !== '') {
            return 'anthropic';
        }
        if (trim((string) getenv('GEMINI_API_KEY')) !== '' || trim((string) getenv('GOOGLE_API_KEY')) !== '') {
            return 'gemini';
        }
        return 'openai';
    }

    private function spawnWorker(string $runId): void
    {
        $workerScript = $this->projectRoot . '/bin/run-worker.php';
        if (!is_file($workerScript)) {
            return;
        }

        @mkdir($this->projectRoot . '/.scratch/runtime', 0777, true);
        $logFile = $this->projectRoot . '/.scratch/runtime/worker.log';
        $cmd = 'php ' . escapeshellarg($workerScript)
            . ' ' . escapeshellarg($this->projectRoot)
            . ' ' . escapeshellarg($runId)
            . ' >> ' . escapeshellarg($logFile)
            . ' 2>&1 &';

        exec($cmd);
    }

    /**
     * @param array<string,mixed> $run
     */
    private function saveCheckpoint(string $runId, array $run): void
    {
        $stages = is_array($run['stages'] ?? null) ? $run['stages'] : [];
        $completedNodes = [];
        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            if ((string) ($stage['status'] ?? '') === 'completed') {
                $completedNodes[] = (string) ($stage['nodeId'] ?? '');
            }
        }

        $checkpoint = [
            'current_node' => (string) ($run['currentNodeId'] ?? ''),
            'completed_nodes' => array_values(array_filter($completedNodes, static fn (string $v): bool => $v !== '')),
            'timestamp' => gmdate('c'),
        ];
        file_put_contents($this->runPath($runId) . '/checkpoint.json', (string) json_encode($checkpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $node
     * @param list<array<string,mixed>> $outgoing
     */
    private function buildStagePrompt(array $run, string $nodeId, array $node, array $outgoing): string
    {
        $context = $this->context((string) ($run['id'] ?? '')) ?? [];
        $label = trim((string) ($node['label'] ?? $nodeId));
        $goal = trim((string) ($run['originalPrompt'] ?? ''));
        $nodePrompt = trim((string) ($node['attrs']['prompt'] ?? ''));

        $prompt = "Run ID: " . (string) ($run['id'] ?? '') . "\n"
            . "Stage Node: {$nodeId}\n"
            . "Stage Label: {$label}\n"
            . "Workflow Goal: " . ($goal !== '' ? $goal : 'No explicit goal provided') . "\n"
            . "Current Context JSON:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if ($this->isValidationNode($nodeId, $node)) {
            $prompt .= "\nYou are the validator stage. Analyze prior outputs and return STRICT JSON only with this schema:\n"
                . "{\"outcome\":\"pass\"|\"fail\",\"reason\":\"short reason\",\"evidence\":[\"item\",\"item\"]}\n"
                . "Use outcome=fail when requirements are missing, incorrect, or unverified.\n";
        } else {
            if ($nodePrompt !== '') {
                $prompt .= "\nStage-specific instruction from DOT node `prompt` attribute:\n"
                    . $nodePrompt . "\n";
            }
            $prompt .= "\nReturn STRICT JSON only using this schema:\n"
                . "{\"summary\":\"short summary\",\"outcome\":\"success\"|\"fail\",\"artifacts\":[{\"path\":\"relative/path.ext\",\"content\":\"full file content\"}],\"commands\":[{\"command\":\"shell command\"}],\"failure_reason\":\"when outcome=fail\"}\n"
                . "Rules:\n"
                . "- Use `artifacts` to deliver requested outputs/files (code, config, docs, images as SVG/XML text, etc.).\n"
                . "- If the user asks for a specific file (example: hello_world.py), include that exact artifact path.\n"
                . "- Commands are optional and should be used only when needed to validate or generate outputs.\n"
                . "- Do not wrap JSON in markdown fences.\n";
        }

        if ($outgoing !== []) {
            $prompt .= "\nOutgoing routes:\n";
            foreach ($outgoing as $edge) {
                $labelText = trim((string) (($edge['attrs']['label'] ?? '') ?: ($edge['attrs']['condition'] ?? '')));
                if ($labelText === '') {
                    $labelText = '(default)';
                }
                $prompt .= '- ' . (string) ($edge['to'] ?? '') . ' [' . $labelText . "]\n";
            }
        }

        return $prompt;
    }

    private function stageSystemPrompt(): string
    {
        return "You are Attractor runtime stage executor.\n"
            . "Follow the stage prompt exactly.\n"
            . "For non-validation stages, return strict JSON action payloads as requested by the prompt.\n"
            . "When validating, return strict JSON as instructed.\n";
    }

    /**
     * @param array<string,mixed> $run
     */
    private function markStageStarted(array &$run, string $nodeId, int $startedAtMs): void
    {
        $this->setStageStatus($run, $nodeId, 'running', $startedAtMs, null, null, '');
        $this->writeStageStatus((string) $run['id'], $nodeId, 'running', null, '');
    }

    /**
     * @param array<string,mixed> $run
     */
    private function markStageCompleted(array &$run, string $nodeId, int $durationMs): void
    {
        $this->setStageStatus($run, $nodeId, 'completed', null, $durationMs, $durationMs, '');
        $this->writeStageStatus((string) $run['id'], $nodeId, 'completed', $durationMs, '');
    }

    /**
     * @param array<string,mixed> $run
     */
    private function markStageCompletedControl(array &$run, string $nodeId, string $message): void
    {
        $now = Time::nowMs();
        $this->setStageStatus($run, $nodeId, 'completed', $now, 1, 1, '');
        $this->writeStageStatus((string) $run['id'], $nodeId, 'completed', 1, '');
        $this->writeStagePrompt((string) $run['id'], $nodeId, "Control stage {$nodeId}\n");
        $this->writeStageResponse((string) $run['id'], $nodeId, $message . "\n");
        $this->appendEvent((string) $run['id'], 'StageStarted', ['nodeId' => $nodeId]);
        $this->appendEvent((string) $run['id'], 'StageCompleted', ['nodeId' => $nodeId, 'durationMs' => 1]);
    }

    /**
     * @param array<string,mixed> $run
     */
    private function markStageFailed(array &$run, string $nodeId, int $durationMs, string $error): void
    {
        $this->setStageStatus($run, $nodeId, 'failed', null, $durationMs, $durationMs, $error);
        $this->writeStageStatus((string) $run['id'], $nodeId, 'failed', $durationMs, $error);
    }

    /**
     * @param array<string,mixed> $run
     */
    private function setStageStatus(
        array &$run,
        string $nodeId,
        string $status,
        ?int $startedAtMs,
        ?int $durationMs,
        ?int $durationForWrite,
        string $error,
    ): void {
        $stages = is_array($run['stages'] ?? null) ? $run['stages'] : [];
        foreach ($stages as $idx => $stage) {
            if (!is_array($stage)) {
                continue;
            }
            if ((string) ($stage['nodeId'] ?? '') !== $nodeId) {
                continue;
            }
            $stages[$idx]['status'] = $status;
            if ($startedAtMs !== null) {
                $stages[$idx]['startedAtMs'] = $startedAtMs;
            }
            if ($durationMs !== null) {
                $stages[$idx]['durationMs'] = $durationMs;
            }
            $stages[$idx]['error'] = $error;
            $run['stages'] = $stages;
            return;
        }

        $stages[] = [
            'index' => count($stages),
            'nodeId' => $nodeId,
            'name' => $nodeId,
            'status' => $status,
            'startedAtMs' => $startedAtMs,
            'durationMs' => $durationForWrite,
            'error' => $error,
            'hasLog' => true,
        ];
        $run['stages'] = $stages;
    }

    private function writeStagePrompt(string $runId, string $nodeId, string $prompt): void
    {
        $stageDir = $this->runPath($runId) . '/' . $nodeId;
        if (!is_dir($stageDir)) {
            mkdir($stageDir, 0777, true);
        }
        file_put_contents($stageDir . '/prompt.md', $prompt);
    }

    private function writeStageResponse(string $runId, string $nodeId, string $response): void
    {
        $stageDir = $this->runPath($runId) . '/' . $nodeId;
        if (!is_dir($stageDir)) {
            mkdir($stageDir, 0777, true);
        }
        file_put_contents($stageDir . '/response.md', $response);
    }

    private function writeStageStatus(string $runId, string $nodeId, string $status, ?int $durationMs, string $error): void
    {
        $stageDir = $this->runPath($runId) . '/' . $nodeId;
        if (!is_dir($stageDir)) {
            mkdir($stageDir, 0777, true);
        }
        $payload = ['status' => $status];
        if ($durationMs !== null) {
            $payload['durationMs'] = $durationMs;
        }
        if ($error !== '') {
            $payload['error'] = $error;
        }
        file_put_contents($stageDir . '/status.json', (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function failRun(string $runId, string $error): void
    {
        $run = $this->getRun($runId);
        if ($run === null) {
            return;
        }
        if ((string) ($run['status'] ?? '') === 'cancelled') {
            return;
        }
        if (!$this->canTransition((string) ($run['status'] ?? ''), 'failed')) {
            return;
        }
        $run['status'] = 'failed';
        $run['finishedAtMs'] = Time::nowMs();
        $this->saveRun($run);
        $this->saveCheckpoint($runId, $run);
        $this->appendEvent($runId, 'PipelineFailed', ['status' => 'failed', 'error' => $error]);
        $this->appendEvent($runId, 'CheckpointSaved', ['nodeId' => (string) ($run['currentNodeId'] ?? '')]);
    }

    /**
     * @param array<string,mixed> $run
     * @param list<array<string,mixed>> $outgoing
     */
    private function startHumanGate(array $run, string $nodeId, array $outgoing): void
    {
        $id = (string) ($run['id'] ?? '');
        $startedAt = Time::nowMs();
        $this->setStageStatus($run, $nodeId, 'waiting_human', $startedAt, null, null, '');
        $this->saveRun($run);
        $this->writeStagePrompt($id, $nodeId, "Human gate stage {$nodeId}. Awaiting operator answer.\n");
        $this->writeStageStatus($id, $nodeId, 'waiting_human', null, '');
        $this->appendEvent($id, 'StageStarted', ['nodeId' => $nodeId]);

        $options = [];
        foreach (array_values($outgoing) as $index => $edge) {
            $key = chr(ord('A') + $index);
            $label = trim((string) (($edge['attrs']['label'] ?? '') ?: 'Route to ' . (string) ($edge['to'] ?? '')));
            $options[] = [
                'key' => $key,
                'label' => $label,
                'target' => (string) ($edge['to'] ?? ''),
            ];
        }
        if ($options === []) {
            $this->failRun($id, 'human gate node has no outgoing edges');
            return;
        }

        $questionId = 'q-' . (string) Time::nowMs();
        $question = [
            'id' => $questionId,
            'stage' => $nodeId,
            'type' => 'MULTIPLE_CHOICE',
            'text' => 'Select next path for human gate stage ' . $nodeId,
            'options' => $options,
        ];
        file_put_contents($this->runPath($id) . '/question.json', (string) json_encode($question, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->appendEvent($id, 'InterviewStarted', ['nodeId' => $nodeId, 'questionId' => $questionId]);
    }

    /**
     * @param list<array<string,mixed>> $outgoing
     */
    private function selectNextNode(array $outgoing, ?string $validationOutcome): ?string
    {
        if ($outgoing === []) {
            return null;
        }

        if ($validationOutcome === null) {
            $next = trim((string) ($outgoing[0]['to'] ?? ''));
            return $next !== '' ? $next : null;
        }

        $wantPass = $validationOutcome === 'pass';
        foreach ($outgoing as $edge) {
            $edgeText = strtolower(trim((string) (($edge['attrs']['label'] ?? '') . ' ' . ($edge['attrs']['condition'] ?? '') . ' ' . ($edge['to'] ?? ''))));
            if ($wantPass && $this->containsAny($edgeText, ['pass', 'success', 'approve', 'yes', 'done'])) {
                $next = trim((string) ($edge['to'] ?? ''));
                if ($next !== '') {
                    return $next;
                }
            }
            if (!$wantPass && $this->containsAny($edgeText, ['fail', 'no', 'reject', 'retry', 'rework', 'fix', 'plan', 'implement'])) {
                $next = trim((string) ($edge['to'] ?? ''));
                if ($next !== '') {
                    return $next;
                }
            }
        }

        $next = trim((string) ($outgoing[0]['to'] ?? ''));
        return $next !== '' ? $next : null;
    }

    /**
     * @return array{outcome:string,reason:string}
     */
    private function parseValidationOutcome(string $response): array
    {
        $trimmed = trim($response);
        if ($trimmed === '') {
            return ['outcome' => 'fail', 'reason' => 'empty validator response'];
        }

        $jsonCandidate = $trimmed;
        if (preg_match('/\{.*\}/s', $trimmed, $match) === 1) {
            $jsonCandidate = (string) ($match[0] ?? $trimmed);
        }
        $decoded = json_decode($jsonCandidate, true);
        if (is_array($decoded)) {
            $outcome = strtolower(trim((string) ($decoded['outcome'] ?? '')));
            if (in_array($outcome, ['pass', 'fail'], true)) {
                return [
                    'outcome' => $outcome,
                    'reason' => trim((string) ($decoded['reason'] ?? 'validator JSON response')),
                ];
            }
        }

        $lower = strtolower($trimmed);
        if (str_contains($lower, '"outcome":"pass"') || preg_match('/\bpass\b/', $lower) === 1) {
            return ['outcome' => 'pass', 'reason' => 'keyword pass detected'];
        }
        return ['outcome' => 'fail', 'reason' => 'keyword fail/default'];
    }

    /**
     * @param list<string> $artifactPaths
     * @param list<array{command:string,exitCode:int,logPath:string}> $commandLogs
     */
    private function formatStageResponse(
        string $response,
        string $provider,
        string $model,
        array $artifactPaths,
        array $commandLogs,
        string $summary,
    ): string {
        $lines = [
            "Provider: {$provider}",
            "Model: {$model}",
        ];
        if ($summary !== '') {
            $lines[] = 'Summary: ' . $summary;
        }
        if ($artifactPaths !== []) {
            $lines[] = 'Artifacts:';
            foreach ($artifactPaths as $path) {
                $lines[] = '- ' . $path;
            }
        }
        if ($commandLogs !== []) {
            $lines[] = 'Commands:';
            foreach ($commandLogs as $log) {
                $lines[] = '- [' . $log['exitCode'] . '] ' . $log['command'] . ' -> ' . $log['logPath'];
            }
        }
        $lines[] = '';
        $lines[] = trim($response);
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * @return array{
     *   artifactPaths:list<string>,
     *   commandLogs:list<array{command:string,exitCode:int,logPath:string}>,
     *   summary:string
     * }
     */
    private function executeStageActions(string $runId, string $nodeId, string $completionText): array
    {
        $parsed = $this->parseStageActionPayload($completionText);
        $summary = trim((string) ($parsed['summary'] ?? ''));

        $artifactPaths = [];
        $artifacts = is_array($parsed['artifacts'] ?? null) ? $parsed['artifacts'] : [];
        foreach ($artifacts as $index => $artifact) {
            if (!is_array($artifact)) {
                continue;
            }
            $rawPath = trim((string) ($artifact['path'] ?? ''));
            $content = (string) ($artifact['content'] ?? '');
            $relative = $this->normalizeArtifactPath($rawPath, $nodeId, $index);
            $fullPath = $this->runPath($runId) . '/artifacts/' . $relative;
            $parent = dirname($fullPath);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            file_put_contents($fullPath, $content);
            $artifactPaths[] = 'artifacts/' . str_replace('\\', '/', $relative);
        }

        if ($artifactPaths === []) {
            $fallbackRelative = $this->normalizeArtifactPath($nodeId . '/model_output.txt', $nodeId, 0);
            $fallbackFull = $this->runPath($runId) . '/artifacts/' . $fallbackRelative;
            $parent = dirname($fallbackFull);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            file_put_contents($fallbackFull, $completionText);
            $artifactPaths[] = 'artifacts/' . str_replace('\\', '/', $fallbackRelative);
        }

        $commandLogs = [];
        $commands = is_array($parsed['commands'] ?? null) ? $parsed['commands'] : [];
        foreach ($commands as $index => $commandItem) {
            $command = '';
            if (is_string($commandItem)) {
                $command = trim($commandItem);
            } elseif (is_array($commandItem)) {
                $command = trim((string) ($commandItem['command'] ?? ''));
            }
            if ($command === '') {
                continue;
            }

            $result = $this->executionEnv->execCommand($command, 120_000, $this->projectRoot);
            $logRelative = 'commands/' . $nodeId . '-' . ($index + 1) . '.log';
            $logFullPath = $this->runPath($runId) . '/artifacts/' . $logRelative;
            $parent = dirname($logFullPath);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            $logBody = "Command: {$command}\nExitCode: {$result->exitCode}\n\nSTDOUT:\n{$result->stdout}\n\nSTDERR:\n{$result->stderr}\n";
            file_put_contents($logFullPath, $logBody);
            $commandLogs[] = [
                'command' => $command,
                'exitCode' => $result->exitCode,
                'logPath' => 'artifacts/' . $logRelative,
            ];
            $artifactPaths[] = 'artifacts/' . $logRelative;
        }

        return [
            'artifactPaths' => array_values(array_unique($artifactPaths)),
            'commandLogs' => $commandLogs,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{summary?:string,artifacts?:array<int,mixed>,commands?:array<int,mixed>}
     */
    private function parseStageActionPayload(string $completionText): array
    {
        $trimmed = trim($completionText);
        if ($trimmed === '') {
            return [];
        }

        $candidate = $trimmed;
        if (preg_match('/\{.*\}/s', $trimmed, $match) === 1) {
            $candidate = (string) ($match[0] ?? $trimmed);
        }
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $fencedArtifacts = [];
        if (preg_match_all('/```([A-Za-z0-9_.+-]*)\n(.*?)```/s', $trimmed, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                $lang = strtolower(trim((string) ($match[1] ?? '')));
                $body = (string) ($match[2] ?? '');
                if ($body === '') {
                    continue;
                }
                $ext = match ($lang) {
                    'py', 'python' => 'py',
                    'js', 'javascript' => 'js',
                    'ts', 'typescript' => 'ts',
                    'sh', 'bash', 'shell' => 'sh',
                    'json' => 'json',
                    'yaml', 'yml' => 'yaml',
                    'xml', 'svg' => $lang,
                    'md', 'markdown' => 'md',
                    default => 'txt',
                };
                $fencedArtifacts[] = [
                    'path' => 'generated/output-' . ($index + 1) . '.' . $ext,
                    'content' => trim($body) . "\n",
                ];
            }
        }

        if ($fencedArtifacts !== []) {
            return [
                'summary' => 'Parsed fenced output',
                'artifacts' => $fencedArtifacts,
            ];
        }

        return [
            'summary' => 'Raw model output',
            'artifacts' => [
                ['path' => 'generated/output.txt', 'content' => $trimmed . "\n"],
            ],
        ];
    }

    private function normalizeArtifactPath(string $rawPath, string $nodeId, int $index): string
    {
        $path = trim(str_replace('\\', '/', $rawPath));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            $path = $nodeId . '/artifact-' . ($index + 1) . '.txt';
        }
        return ltrim($path, '/');
    }

    /**
     * @param array<string,mixed> $node
     * @param list<array<string,mixed>> $outgoing
     */
    private function isTerminalNode(string $nodeId, array $node, array $outgoing): bool
    {
        $id = strtolower($nodeId);
        if ($id === 'done') {
            return true;
        }
        $shape = strtolower(trim((string) ($node['attrs']['shape'] ?? '')));
        if ($shape === 'msquare') {
            return true;
        }
        return $outgoing === [] && !$this->isHumanGateNode($nodeId, $node);
    }

    /**
     * @param array<string,mixed> $node
     */
    private function isHumanGateNode(string $nodeId, array $node): bool
    {
        $id = strtolower($nodeId);
        $type = strtolower(trim((string) ($node['attrs']['type'] ?? '')));
        $shape = strtolower(trim((string) ($node['attrs']['shape'] ?? '')));
        $label = strtolower(trim((string) ($node['label'] ?? '')));

        if ($type === 'wait.human' || str_contains($type, 'human')) {
            return true;
        }
        if ($shape === 'hexagon') {
            return true;
        }
        return str_contains($id, 'gate') && str_contains($label, 'human');
    }

    /**
     * @param array<string,mixed> $node
     */
    private function isValidationNode(string $nodeId, array $node): bool
    {
        $goalGate = strtolower(trim((string) ($node['attrs']['goal_gate'] ?? '')));
        if (in_array($goalGate, ['true', '1', 'yes'], true)) {
            return true;
        }
        $needle = strtolower($nodeId . ' ' . (string) ($node['label'] ?? '') . ' ' . (string) ($node['attrs']['type'] ?? ''));
        return $this->containsAny($needle, ['validate', 'validation', 'verify', 'review', 'qa', 'check', 'test', 'audit']);
    }

    /**
     * @param array<string,mixed> $node
     */
    private function isControlNode(string $nodeId, array $node): bool
    {
        $id = strtolower($nodeId);
        if ($id === 'start' || $id === 'done') {
            return true;
        }
        $type = strtolower(trim((string) ($node['attrs']['type'] ?? '')));
        return $type === 'control';
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{
     *   startNodeId:string,
     *   nodes:array<string,array{id:string,label:string,attrs:array<string,string>}>,
     *   edges:list<array{from:string,to:string,attrs:array<string,string>}>,
     *   outgoing:array<string,list<array{from:string,to:string,attrs:array<string,string>}>>,
     *   stageOrder:list<string>
     * }
     */
    private function parseGraph(string $dot): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $dot);

        /** @var array<string,array{id:string,label:string,attrs:array<string,string>}> $nodes */
        $nodes = [];
        $nodeOrder = [];

        if (preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*\[(.*?)\]\s*;?\s*$/m', $normalized, $nodeMatches, PREG_SET_ORDER)) {
            foreach ($nodeMatches as $match) {
                $nodeId = (string) ($match[1] ?? '');
                $attrText = (string) ($match[2] ?? '');
                if ($nodeId === '') {
                    continue;
                }
                $attrs = $this->parseAttrList($attrText);
                $label = (string) ($attrs['label'] ?? $nodeId);
                $nodes[$nodeId] = [
                    'id' => $nodeId,
                    'label' => $label,
                    'attrs' => $attrs,
                ];
                if (!in_array($nodeId, $nodeOrder, true)) {
                    $nodeOrder[] = $nodeId;
                }
            }
        }

        /** @var list<array{from:string,to:string,attrs:array<string,string>}> $edges */
        $edges = [];
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*->\s*([A-Za-z_][A-Za-z0-9_]*)(?:\s*\[(.*?)\])?\s*;?/m', $normalized, $edgeMatches, PREG_SET_ORDER)) {
            foreach ($edgeMatches as $match) {
                $from = (string) ($match[1] ?? '');
                $to = (string) ($match[2] ?? '');
                $attrText = (string) ($match[3] ?? '');
                if ($from === '' || $to === '') {
                    continue;
                }

                if (!isset($nodes[$from])) {
                    $nodes[$from] = ['id' => $from, 'label' => $from, 'attrs' => []];
                }
                if (!isset($nodes[$to])) {
                    $nodes[$to] = ['id' => $to, 'label' => $to, 'attrs' => []];
                }
                if (!in_array($from, $nodeOrder, true)) {
                    $nodeOrder[] = $from;
                }
                if (!in_array($to, $nodeOrder, true)) {
                    $nodeOrder[] = $to;
                }

                $edges[] = [
                    'from' => $from,
                    'to' => $to,
                    'attrs' => $this->parseAttrList($attrText),
                ];
            }
        }

        if ($nodes === []) {
            $nodes = [
                'start' => ['id' => 'start', 'label' => 'start', 'attrs' => []],
                'done' => ['id' => 'done', 'label' => 'done', 'attrs' => ['shape' => 'Msquare']],
            ];
            $edges = [['from' => 'start', 'to' => 'done', 'attrs' => []]];
            $nodeOrder = ['start', 'done'];
        }

        $indegree = array_fill_keys(array_keys($nodes), 0);
        $outgoing = [];
        foreach ($edges as $edge) {
            $to = $edge['to'];
            $from = $edge['from'];
            $indegree[$to] = (int) ($indegree[$to] ?? 0) + 1;
            $outgoing[$from] ??= [];
            $outgoing[$from][] = $edge;
        }

        $startNodeId = 'start';
        if (!isset($nodes[$startNodeId])) {
            $startNodeId = '';
            foreach ($nodeOrder as $nodeId) {
                if ((int) ($indegree[$nodeId] ?? 0) === 0) {
                    $startNodeId = $nodeId;
                    break;
                }
            }
            if ($startNodeId === '') {
                $startNodeId = $nodeOrder[0] ?? array_key_first($nodes) ?? 'start';
            }
        }

        $stageOrder = $this->deriveStageOrder($startNodeId, $outgoing, $nodeOrder);
        foreach ($nodeOrder as $nodeId) {
            if (!in_array($nodeId, $stageOrder, true)) {
                $stageOrder[] = $nodeId;
            }
        }

        return [
            'startNodeId' => $startNodeId,
            'nodes' => $nodes,
            'edges' => $edges,
            'outgoing' => $outgoing,
            'stageOrder' => $stageOrder,
        ];
    }

    /**
     * @param array<string,list<array{from:string,to:string,attrs:array<string,string>}>> $outgoing
     * @param list<string> $fallbackOrder
     * @return list<string>
     */
    private function deriveStageOrder(string $startNodeId, array $outgoing, array $fallbackOrder): array
    {
        $visited = [];
        $order = [];
        $stack = [$startNodeId];

        while ($stack !== []) {
            $nodeId = array_shift($stack);
            if (!is_string($nodeId) || $nodeId === '' || isset($visited[$nodeId])) {
                continue;
            }
            $visited[$nodeId] = true;
            $order[] = $nodeId;

            foreach (($outgoing[$nodeId] ?? []) as $edge) {
                $to = (string) ($edge['to'] ?? '');
                if ($to !== '' && !isset($visited[$to])) {
                    $stack[] = $to;
                }
            }
        }

        if ($order === []) {
            return $fallbackOrder;
        }

        return $order;
    }

    /**
     * @return array<string,string>
     */
    private function parseAttrList(string $attrText): array
    {
        $attrs = [];
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^,\\]]+)/', $attrText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = trim((string) ($match[1] ?? ''));
                $value = trim((string) ($match[2] ?? ''));
                if ($key === '') {
                    continue;
                }
                if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                    $value = substr($value, 1, -1);
                }
                $attrs[$key] = stripcslashes($value);
            }
        }
        return $attrs;
    }

    /**
     * @param array<string,mixed> $graph
     */
    private function stageName(string $nodeId, array $graph): string
    {
        $node = $graph['nodes'][$nodeId] ?? null;
        if (!is_array($node)) {
            return ucfirst($nodeId);
        }
        $label = trim((string) ($node['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        return ucfirst($nodeId);
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }
        return substr($value, 0, $limit) . '...';
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

    private function canTransition(string $from, string $to): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
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
