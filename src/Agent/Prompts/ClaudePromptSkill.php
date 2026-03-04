<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

final class ClaudePromptSkill
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $content,
    ) {
    }
}
