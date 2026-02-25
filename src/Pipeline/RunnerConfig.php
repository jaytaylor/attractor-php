<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

final class RunnerConfig
{
    public function __construct(
        public readonly string $logsRoot,
        public readonly ?string $preferredLabel = null,
        public readonly bool $autoStatus = true,
        public readonly ?Observer $observer = null,
    ) {
    }
}
