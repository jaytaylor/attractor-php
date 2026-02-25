<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Http;

final class RunRepository
{
    public function __construct(private readonly string $root = '.scratch/http-runs')
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function ensureExists(): void
    {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0777, true);
        }
    }

    /** @param array<string, mixed> $record */
    public function put(string $runId, array $record): void
    {
        $this->ensureExists();
        $path = $this->root . '/' . $runId . '.json';
        file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    public function get(string $runId): ?array
    {
        $path = $this->root . '/' . $runId . '.json';
        if (!is_file($path)) {
            return null;
        }

        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
