<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Exec\ExecutionEnvironment;

final class ProjectDocDiscovery
{
    public static function discover(string $providerId, ExecutionEnvironment $executionEnv): ?string
    {
        $workingDir = $executionEnv->workingDirectory();

        $providerFiles = match ($providerId) {
            'openai' => ['AGENTS.md', '.codex/instructions.md'],
            'anthropic' => ['AGENTS.md', 'CLAUDE.md'],
            'gemini' => ['AGENTS.md', 'GEMINI.md'],
            default => ['AGENTS.md'],
        };

        $docs = [];
        $totalBytes = 0;
        $budget = 32 * 1024;

        $gitRoot = self::findGitRoot($executionEnv, $workingDir);
        $searchDirs = ($gitRoot !== null && $gitRoot !== $workingDir) ? [$gitRoot, $workingDir] : [$workingDir];

        foreach ($searchDirs as $dir) {
            foreach ($providerFiles as $fileName) {
                $path = rtrim($dir, '/') . '/' . $fileName;
                if (!$executionEnv->fileExists($path)) {
                    continue;
                }

                try {
                    $content = $executionEnv->readFile($path);
                } catch (\Throwable) {
                    continue;
                }

                $contentLength = strlen($content);
                if ($totalBytes + $contentLength <= $budget) {
                    $docs[] = '# ' . $fileName . "\n" . $content;
                    $totalBytes += $contentLength;
                    continue;
                }

                $remaining = $budget - $totalBytes;
                if ($remaining > 0) {
                    $docs[] = '# ' . $fileName . "\n" . substr($content, 0, $remaining)
                        . "\n[Project instructions truncated at 32KB]";
                }

                return implode("\n\n", $docs);
            }
        }

        return $docs === [] ? null : implode("\n\n", $docs);
    }

    public static function findGitRoot(ExecutionEnvironment $executionEnv, string $workingDir): ?string
    {
        try {
            $result = $executionEnv->execCommand('git rev-parse --show-toplevel', 5000, $workingDir);
            if ($result->exitCode === 0) {
                $root = trim($result->stdout);
                return $root !== '' ? $root : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
