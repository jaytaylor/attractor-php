<?php

declare(strict_types=1);

namespace Attractor\Agent\Profiles;

final class AnthropicProfile extends BaseProfile
{
    public function id(): string
    {
        return 'anthropic';
    }

    public function supportsParallelToolCalls(): bool
    {
        return true;
    }

    public function contextWindowSize(): int
    {
        return 200_000;
    }

    protected function baseInstructions(): string
    {
        return 'You are a coding agent aligned with Claude Code behavior. Keep plans explicit and tool calls disciplined.';
    }
}
