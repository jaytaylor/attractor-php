<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Exec\ExecutionEnvironment;
use App\Agent\Profiles\GitContext;
use App\Agent\Profiles\ProviderProfile;

final class Session
{
    public function __construct(
        private readonly ProviderProfile $providerProfile,
        private readonly ExecutionEnvironment $executionEnv,
        private readonly ?string $userInstructions = null,
    ) {
    }

    public function buildSystemPrompt(): string
    {
        $projectDocs = ProjectDocDiscovery::discover($this->providerProfile->id(), $this->executionEnv);
        $gitContext = $this->gatherGitContext();

        return $this->providerProfile->buildSystemPrompt(
            environment: $this->executionEnv,
            projectDocs: $projectDocs,
            userInstructions: $this->userInstructions,
            gitContext: $gitContext,
        );
    }

    public function gatherGitContext(): ?GitContext
    {
        $workingDir = $this->executionEnv->workingDirectory();
        if (ProjectDocDiscovery::findGitRoot($this->executionEnv, $workingDir) === null) {
            return null;
        }

        $branch = null;
        $modifiedFileCount = 0;
        $recentCommits = null;

        try {
            $result = $this->executionEnv->execCommand('git rev-parse --abbrev-ref HEAD', 5000, $workingDir);
            if ($result->exitCode === 0) {
                $branch = trim($result->stdout);
            }
        } catch (\Throwable) {
        }

        try {
            $result = $this->executionEnv->execCommand('git status --porcelain | wc -l', 5000, $workingDir);
            if ($result->exitCode === 0) {
                $modifiedFileCount = (int) trim($result->stdout);
            }
        } catch (\Throwable) {
        }

        try {
            $result = $this->executionEnv->execCommand('git log --oneline -5', 5000, $workingDir);
            if ($result->exitCode === 0) {
                $recentCommits = trim($result->stdout);
            }
        } catch (\Throwable) {
        }

        return new GitContext(
            branch: $branch,
            modifiedFileCount: $modifiedFileCount,
            recentCommits: $recentCommits,
        );
    }
}
