<?php

declare(strict_types=1);

namespace Attractor\Agent\Exec;

use Attractor\Agent\ExecutionEnvironment;

final class LocalExecutionEnvironment implements ExecutionEnvironment
{
    /** @param list<string> $sensitiveEnvPatterns */
    public function __construct(
        private readonly string $cwd,
        private readonly array $sensitiveEnvPatterns = ['/_API_KEY$/', '/_SECRET$/', '/TOKEN$/', '/PASSWORD$/'],
    ) {
    }

    public function workingDirectory(): string
    {
        return $this->cwd;
    }

    public function readFile(string $path, ?int $offset = null, ?int $limit = null): string
    {
        $resolved = $this->resolvePath($path);
        $lines = @file($resolved, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new \RuntimeException("failed reading file: {$path}");
        }

        $start = max(0, ($offset ?? 1) - 1);
        $slice = $limit === null ? array_slice($lines, $start) : array_slice($lines, $start, $limit);

        $out = [];
        foreach ($slice as $idx => $line) {
            $lineNo = $start + $idx + 1;
            $out[] = sprintf('%d | %s', $lineNo, $line);
        }

        return implode("\n", $out);
    }

    public function writeFile(string $path, string $content): void
    {
        $resolved = $this->resolvePath($path);
        $parent = dirname($resolved);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($resolved, $content);
    }

    public function fileExists(string $path): bool
    {
        return file_exists($this->resolvePath($path));
    }

    public function execCommand(string $command, int $timeoutMs, ?string $workingDir = null, ?array $envVars = null): ExecResult
    {
        $cwd = $workingDir !== null ? $this->resolvePath($workingDir) : $this->cwd;
        $filteredEnv = $this->filteredEnv($envVars ?? []);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['/bin/bash', '-lc', $command], $desc, $pipes, $cwd, $filteredEnv);
        if (!is_resource($process)) {
            throw new \RuntimeException('failed to start process');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            if (!$status['running']) {
                break;
            }

            $elapsedMs = (int) ((microtime(true) - $start) * 1000);
            if ($elapsedMs > $timeoutMs) {
                $timedOut = true;
                proc_terminate($process, SIGTERM);
                usleep(2_000_000);
                $statusAfterTerm = proc_get_status($process);
                if ($statusAfterTerm['running']) {
                    proc_terminate($process, SIGKILL);
                }
                break;
            }

            usleep(20_000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($timedOut) {
            $stderr = trim($stderr . "\nCommand timed out after {$timeoutMs}ms") ;
        }

        return new ExecResult(
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $timedOut ? 124 : $exitCode,
            timedOut: $timedOut,
            fullOutput: trim($stdout . ($stderr !== '' ? "\n" . $stderr : '')),
        );
    }

    public function grep(string $pattern, string $path, GrepOptions $options): string
    {
        $target = $this->resolvePath($path);
        $flags = $options->caseInsensitive ? '-nHi' : '-nH';
        $cmd = sprintf('rg %s %s %s', $flags, escapeshellarg($pattern), escapeshellarg($target));
        $result = $this->execCommand($cmd, 10_000, $this->cwd);

        if ($result->exitCode !== 0 && $result->exitCode !== 1) {
            throw new \RuntimeException('grep failed: ' . $result->stderr);
        }

        $lines = preg_split('/\R/', trim($result->stdout)) ?: [];
        $lines = array_filter($lines, static fn (string $line): bool => $line !== '');
        return implode("\n", array_slice(array_values($lines), 0, $options->maxMatches));
    }

    public function glob(string $pattern, string $path): array
    {
        $base = $this->resolvePath($path);
        $result = glob($base . '/' . ltrim($pattern, '/'));

        return array_values(array_map(fn (string $p): string => $this->relativePath($p), is_array($result) ? $result : []));
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->cwd . '/' . ltrim($path, '/');
    }

    private function relativePath(string $path): string
    {
        if (str_starts_with($path, $this->cwd . '/')) {
            $rel = substr($path, strlen($this->cwd) + 1);
            return preg_replace('/^\\.\\//', '', $rel) ?? $rel;
        }

        return $path;
    }

    /** @param array<string, string> $envVars */
    private function filteredEnv(array $envVars): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            $blocked = false;
            foreach ($this->sensitiveEnvPatterns as $pattern) {
                if (preg_match($pattern, $key) === 1) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked && is_string($value)) {
                $env[$key] = $value;
            }
        }

        foreach ($envVars as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }
}
