<?php

declare(strict_types=1);

namespace Attractor\Agent;

use Attractor\Agent\Exec\ExecResult;
use Attractor\Agent\Exec\GrepOptions;

interface ExecutionEnvironment
{
    public function workingDirectory(): string;

    public function readFile(string $path, ?int $offset = null, ?int $limit = null): string;

    public function writeFile(string $path, string $content): void;

    public function fileExists(string $path): bool;

    /** @param array<string, string> $envVars */
    public function execCommand(string $command, int $timeoutMs, ?string $workingDir = null, ?array $envVars = null): ExecResult;

    public function grep(string $pattern, string $path, GrepOptions $options): string;

    /** @return list<string> */
    public function glob(string $pattern, string $path): array;
}
