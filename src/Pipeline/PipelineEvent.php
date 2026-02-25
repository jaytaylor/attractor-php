<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

final class PipelineEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $type,
        public readonly array $payload = [],
    ) {
    }
}
