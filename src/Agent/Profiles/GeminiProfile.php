<?php

declare(strict_types=1);

namespace Attractor\Agent\Profiles;

final class GeminiProfile extends BaseProfile
{
    public function id(): string
    {
        return 'gemini';
    }

    public function supportsParallelToolCalls(): bool
    {
        return true;
    }

    protected function baseInstructions(): string
    {
        return 'You are a coding agent aligned with gemini-cli behavior. Execute deterministic tool actions and summarize outcomes.';
    }
}
