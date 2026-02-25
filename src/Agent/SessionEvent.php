<?php

declare(strict_types=1);

namespace Attractor\Agent;

final class SessionEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $kind,
        public readonly array $data = [],
    ) {
    }
}
