<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class StreamEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {
    }
}
