<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

final class PipelineOutcome
{
    /** @param list<string> $completedNodes */
    public function __construct(
        public readonly string $status,
        public readonly array $completedNodes,
        public readonly string $logsRoot,
        public readonly ?string $message = null,
    ) {
    }
}
