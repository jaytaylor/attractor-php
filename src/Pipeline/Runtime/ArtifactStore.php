<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Runtime;

final class ArtifactStore
{
    public function __construct(private readonly string $runDir)
    {
    }

    public function runDir(): string
    {
        return $this->runDir;
    }

    public function ensureRunDir(): void
    {
        if (!is_dir($this->runDir)) {
            mkdir($this->runDir, 0777, true);
        }
        if (!is_dir($this->runDir . '/artifacts')) {
            mkdir($this->runDir . '/artifacts', 0777, true);
        }
    }

    public function nodeDir(string $nodeId): string
    {
        $path = $this->runDir . '/' . $nodeId;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    public function writeNodeFile(string $nodeId, string $name, string $content): string
    {
        $path = $this->nodeDir($nodeId) . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    /** @param array<string, mixed> $status */
    public function writeStatus(string $nodeId, array $status): string
    {
        return $this->writeNodeFile($nodeId, 'status.json', json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function writeManifest(array $data): string
    {
        $path = $this->runDir . '/manifest.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    public function writeCheckpoint(Checkpoint $checkpoint): string
    {
        $path = $this->runDir . '/checkpoint.json';
        file_put_contents($path, $checkpoint->toJson());

        return $path;
    }

    public function readCheckpoint(): ?Checkpoint
    {
        $path = $this->runDir . '/checkpoint.json';
        if (!is_file($path)) {
            return null;
        }

        return Checkpoint::fromJson((string) file_get_contents($path));
    }
}
