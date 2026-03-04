<?php

declare(strict_types=1);

namespace App\Agent\Exec;

interface ExecutionEnvironment
{
    public function workingDirectory(): string;

    public function platform(): string;

    public function osVersion(): string;

    public function fileExists(string $path): bool;

    public function readFile(string $path, ?int $offset = null, ?int $limit = null): string;

    public function execCommand(string $command, int $timeoutMs, ?string $workingDir = null): ExecResult;
}
