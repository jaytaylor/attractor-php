<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $reasoningTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {
    }

    public function add(self $other): self
    {
        return new self(
            inputTokens: $this->inputTokens + $other->inputTokens,
            outputTokens: $this->outputTokens + $other->outputTokens,
            reasoningTokens: $this->reasoningTokens + $other->reasoningTokens,
            cacheReadTokens: $this->cacheReadTokens + $other->cacheReadTokens,
            cacheWriteTokens: $this->cacheWriteTokens + $other->cacheWriteTokens,
        );
    }
}
