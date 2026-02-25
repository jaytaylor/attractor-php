<?php

declare(strict_types=1);

namespace Attractor\Agent;

final class BufferedEventEmitter implements EventEmitter
{
    /** @var list<SessionEvent> */
    private array $events = [];

    public function emit(SessionEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<SessionEvent> */
    public function all(): array
    {
        return $this->events;
    }
}
