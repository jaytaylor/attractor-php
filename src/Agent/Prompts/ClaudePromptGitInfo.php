<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

final class ClaudePromptGitInfo
{
    public function __construct(
        public readonly ?string $branch,
        public readonly bool $hasUncommittedChanges,
        public readonly ?string $recentCommits,
    ) {
    }
}
