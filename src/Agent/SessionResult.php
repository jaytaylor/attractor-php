<?php

declare(strict_types=1);

namespace Attractor\Agent;

final class SessionResult
{
    /** @param list<SessionEvent> $events */
    public function __construct(
        public readonly string $text,
        public readonly array $events,
    ) {
    }
}
