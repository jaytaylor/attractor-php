<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

final class ClaudePromptEnvironment
{
    public function __construct(
        public readonly string $workingDirectory,
        public readonly ?ClaudePromptGitInfo $gitInfo,
    ) {
    }
}
