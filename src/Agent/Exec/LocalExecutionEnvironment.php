<?php

declare(strict_types=1);

namespace App\Agent\Exec;

use RuntimeException;

final class LocalExecutionEnvironment implements ExecutionEnvironment
{
    public function __construct(private readonly string $workingDirectory)
    {
    }

    public function workingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function platform(): string
    {
        return strtolower(PHP_OS_FAMILY);
    }

    public function osVersion(): string
    {
        return php_uname('s') . ' ' . php_uname('r');
    }

    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    public function readFile(string $path, ?int $offset = null, ?int $limit = null): string
    {
        $content = @file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('failed to read file: ' . $path);
        }

        if ($offset !== null || $limit !== null) {
            $start = $offset ?? 0;
            $length = $limit;
            return $length !== null ? substr($content, $start, $length) : substr($content, $start);
        }

        return $content;
    }

    public function execCommand(string $command, int $timeoutMs, ?string $workingDir = null): ExecResult
    {
        $cwd = $workingDir ?? $this->workingDirectory;
        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
        $wrapped = 'cd ' . escapeshellarg($cwd) . ' && timeout ' . $timeoutSeconds . ' bash -lc ' . escapeshellarg($command);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($wrapped, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('failed to execute command');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        return new ExecResult(
            exitCode: is_int($exitCode) ? $exitCode : 1,
            stdout: is_string($stdout) ? $stdout : '',
            stderr: is_string($stderr) ? $stderr : '',
        );
    }
}
