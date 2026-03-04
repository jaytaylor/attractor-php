<?php

declare(strict_types=1);

namespace App\Agent\Exec;

final class ExecResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }
}
