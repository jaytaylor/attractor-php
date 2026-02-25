<?php

declare(strict_types=1);

namespace Attractor\Agent\Exec;

final class GrepOptions
{
    public function __construct(
        public readonly bool $caseInsensitive = false,
        public readonly int $maxMatches = 100,
    ) {
    }
}
