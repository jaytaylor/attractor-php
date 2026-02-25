<?php

declare(strict_types=1);

namespace Attractor\Agent\Exec;

final class ExecResult
{
    public function __construct(
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $exitCode,
        public readonly bool $timedOut = false,
        public readonly string $fullOutput = '',
    ) {
    }

    public function combinedOutput(): string
    {
        return $this->stdout . ($this->stderr !== '' ? "\n" . $this->stderr : '');
    }
}
