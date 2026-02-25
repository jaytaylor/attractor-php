<?php

declare(strict_types=1);

namespace Attractor\Agent;

final class SessionConfig
{
    public function __construct(
        public readonly int $maxToolRoundsPerInput = 8,
        public readonly int $maxTurns = 100,
        public readonly int $defaultCommandTimeoutMs = 10000,
        public readonly int $readFileMaxChars = 50000,
        public readonly int $shellMaxChars = 30000,
        public readonly int $grepMaxChars = 20000,
        public readonly int $globMaxChars = 20000,
        public readonly int $shellMaxLines = 256,
        public readonly int $grepMaxLines = 200,
        public readonly int $globMaxLines = 500,
        public readonly ?string $reasoningEffort = null,
        public readonly int $maxSubagentDepth = 1,
    ) {
    }
}
