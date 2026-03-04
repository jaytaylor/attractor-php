<?php

declare(strict_types=1);

namespace App\Agent\Profiles;

final class GitContext
{
    public function __construct(
        public readonly ?string $branch = null,
        public readonly int $modifiedFileCount = 0,
        public readonly ?string $recentCommits = null,
    ) {
    }
}
