<?php

declare(strict_types=1);

namespace Attractor\Agent\Profiles;

final class OpenAIProfile extends BaseProfile
{
    public function id(): string
    {
        return 'openai';
    }

    public function supportsParallelToolCalls(): bool
    {
        return true;
    }

    protected function baseInstructions(): string
    {
        return 'You are a coding agent aligned with codex-style tool use. Prefer precise edits and concise reporting.';
    }
}
