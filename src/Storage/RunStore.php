<?php

declare(strict_types=1);

namespace AttractorPhp\Storage;

use AttractorPhp\Http\ApiError;
use ZipArchive;

final class RunStore
{
    public function __construct(private readonly string $logsRoot)
    {
        if (!is_dir($this->logsRoot)) {
            mkdir($this->logsRoot, 0777, true);
        }
    }

    public function logsRoot(): string
    {
        return $this->logsRoot;
    }

    /** @return list<array<string,mixed>> */
    public function listRuns(bool $includeArchived = false): array
    {
        $items = [];
        $entries = @scandir($this->logsRoot) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $manifestPath = $this->runDir($entry) . '/manifest.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $run = $this->readJson($manifestPath);
            if (($run['archived'] ?? false) && !$includeArchived) {
                continue;
            }
            $items[] = $run;
        }

        usort($items, static function (array $a, array $b): int {
            return (int) ($b['startedAtMs'] ?? 0) <=> (int) ($a['startedAtMs'] ?? 0);
        });

        return $items;
    }

    /** @return array<string,mixed> */
    public function createRun(array $input): array
    {
        $now = (int) floor(microtime(true) * 1000);
        $id = 'run-' . $now . '-' . random_int(1000, 9999);
        $dir = $this->runDir($id);

        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to create run directory');
        }

        mkdir($dir . '/artifacts', 0777, true);

        $manifest = [
            'id' => $id,
            'displayName' => (string) ($input['displayName'] ?? ''),
            'fileName' => (string) ($input['fileName'] ?? ''),
            'status' => 'running',
            'archived' => false,
            'simulate' => (bool) ($input['simulate'] ?? false),
            'autoApprove' => (bool) ($input['autoApprove'] ?? true),
            'familyId' => (string) ($input['familyId'] ?? $id),
            'originalPrompt' => (string) ($input['originalPrompt'] ?? ''),
            'startedAtMs' => $now,
            'finishedAtMs' => null,
            'currentNodeId' => 'start',
            'stages' => [],
            'logs' => [],
            'dotSource' => (string) ($input['dotSource'] ?? ''),
        ];

        $this->writeJson($dir . '/manifest.json', $manifest);
        $this->writeJson($dir . '/checkpoint.json', [
            'current_node' => 'start',
            'completed_nodes' => [],
            'timestamp' => gmdate('c'),
        ]);
        $this->writeJson($dir . '/context.json', [
            'graph.goal' => $manifest['originalPrompt'] !== '' ? $manifest['originalPrompt'] : 'N/A',
        ]);
        file_put_contents($dir . '/dot.dot', $manifest['dotSource']);
        file_put_contents($dir . '/events.ndjson', '');
        $this->writeJson($dir . '/questions.json', []);

        return $manifest;
    }

    /** @return array<string,mixed> */
    public function getRun(string $runId): array
    {
        $path = $this->runDir($runId) . '/manifest.json';
        if (!is_file($path)) {
            throw new ApiError(404, 'NOT_FOUND', 'run not found');
        }

        return $this->readJson($path);
    }

    public function saveRun(string $runId, array $run): void
    {
        $this->writeJson($this->runDir($runId) . '/manifest.json', $run);
    }

    /** @param array<string,mixed> $payload */
    public function emitEvent(string $runId, string $type, array $payload = []): void
    {
        $event = [
            'runId' => $runId,
            'tsMs' => (int) floor(microtime(true) * 1000),
            'type' => $type,
            'payload' => $payload,
        ];

        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }

        file_put_contents($this->runDir($runId) . '/events.ndjson', $line . "\n", FILE_APPEND);

        $run = $this->getRun($runId);
        $run['logs'][] = sprintf('[%s] %s', gmdate('c'), $type);
        $this->saveRun($runId, $run);
    }

    /** @return list<array<string,mixed>> */
    public function readEvents(string $runId): array
    {
        $path = $this->runDir($runId) . '/events.ndjson';
        if (!is_file($path)) {
            return [];
        }

        $events = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    public function readGlobalEvents(): array
    {
        $events = [];
        foreach ($this->listRuns(true) as $run) {
            $events = array_merge($events, $this->readEvents((string) $run['id']));
        }

        usort($events, static fn(array $a, array $b): int => ((int) ($a['tsMs'] ?? 0)) <=> ((int) ($b['tsMs'] ?? 0)));
        return $events;
    }

    /** @return list<array<string,mixed>> */
    public function getQuestions(string $runId): array
    {
        $path = $this->runDir($runId) . '/questions.json';
        if (!is_file($path)) {
            return [];
        }

        $questions = $this->readJson($path);
        return is_array($questions) ? array_values($questions) : [];
    }

    /** @param list<array<string,mixed>> $questions */
    public function saveQuestions(string $runId, array $questions): void
    {
        $this->writeJson($this->runDir($runId) . '/questions.json', $questions);
    }

    /** @return array<string,mixed> */
    public function readCheckpoint(string $runId): array
    {
        $path = $this->runDir($runId) . '/checkpoint.json';
        if (!is_file($path)) {
            throw new ApiError(404, 'NOT_FOUND', 'run not found');
        }
        return $this->readJson($path);
    }

    public function saveCheckpoint(string $runId, array $checkpoint): void
    {
        $this->writeJson($this->runDir($runId) . '/checkpoint.json', $checkpoint);
    }

    /** @return array<string,mixed> */
    public function readContext(string $runId): array
    {
        $path = $this->runDir($runId) . '/context.json';
        if (!is_file($path)) {
            throw new ApiError(404, 'NOT_FOUND', 'run not found');
        }
        return $this->readJson($path);
    }

    public function saveContext(string $runId, array $context): void
    {
        $this->writeJson($this->runDir($runId) . '/context.json', $context);
    }

    public function setArchived(string $runId, bool $archived): void
    {
        $run = $this->getRun($runId);
        $run['archived'] = $archived;
        $this->saveRun($runId, $run);
    }

    public function runDir(string $runId): string
    {
        return rtrim($this->logsRoot, '/') . '/' . $runId;
    }

    /** @return list<array{path:string,sizeBytes:int,isText:bool}> */
    public function listArtifacts(string $runId): array
    {
        $base = $this->runDir($runId) . '/artifacts';
        if (!is_dir($base)) {
            throw new ApiError(404, 'NOT_FOUND', 'run not found');
        }

        $items = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $rel = ltrim(str_replace($base, '', $full), '/');
            $sample = file_get_contents($full, false, null, 0, 2048) ?: '';
            $isText = !preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $sample);
            $items[] = [
                'path' => str_replace('\\', '/', $rel),
                'sizeBytes' => $file->getSize(),
                'isText' => (bool) $isText,
            ];
        }

        usort($items, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $items;
    }

    /** @return array{full:string,content:string,isText:bool} */
    public function readArtifact(string $runId, string $relativePath): array
    {
        $base = realpath($this->runDir($runId) . '/artifacts');
        if ($base === false) {
            throw new ApiError(404, 'NOT_FOUND', 'run not found');
        }

        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            throw new ApiError(400, 'BAD_REQUEST', 'invalid artifact path');
        }

        $full = realpath($base . '/' . $relativePath);
        if ($full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            throw new ApiError(404, 'NOT_FOUND', 'artifact not found');
        }

        $content = file_get_contents($full);
        if ($content === false) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to read artifact');
        }

        $isText = !preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', substr($content, 0, 2048));

        return [
            'full' => $full,
            'content' => $content,
            'isText' => (bool) $isText,
        ];
    }

    public function createArtifactsZip(string $runId): string
    {
        $runDir = $this->runDir($runId);
        if (!is_dir($runDir)) {
            throw new ApiError(404, 'NOT_FOUND', 'run not found');
        }

        $zipPath = $runDir . '/artifacts.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to create zip');
        }

        $artifactDir = $runDir . '/artifacts';
        if (is_dir($artifactDir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($artifactDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $full = $file->getPathname();
                $rel = ltrim(str_replace($artifactDir, '', $full), '/');
                $zip->addFile($full, $rel);
            }
        }

        $zip->close();
        return $zipPath;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to read file');
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed>|list<mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to encode json');
        }
        file_put_contents($path, $encoded . "\n");
    }
}
