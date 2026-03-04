<?php

declare(strict_types=1);

namespace App\Agent\Profiles;

use App\Agent\Exec\ExecutionEnvironment;

interface ProviderProfile
{
    public function id(): string;

    public function model(): string;

    /**
     * @return list<string>
     */
    public function toolNames(): array;

    public function buildSystemPrompt(
        ExecutionEnvironment $environment,
        ?string $projectDocs,
        ?string $userInstructions,
        ?GitContext $gitContext,
    ): string;

    public function supportsReasoning(): bool;

    public function supportsStreaming(): bool;

    public function supportsParallelToolCalls(): bool;

    public function contextWindowSize(): int;
}
